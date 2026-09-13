<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Presentation\ApiResource;

use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/anonymous-cv, en
 * miroir du test du endpoint public (AnonymousCvSectionResourceTest) — même
 * pattern que BackofficeCaseStudyResourceTest.
 */
final class BackofficeAnonymousCvSectionResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM anonymous_cv_section');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/anonymous-cv');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/anonymous-cv');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * `achievements` vide → 422 : la ligne éditoriale (pas de section sans
     * réalisation) est un contrat d'API, pas une politesse du formulaire.
     */
    public function testASectionWithoutAchievementsIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/anonymous-cv', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Backend PHP / Symfony',
            'skills' => 'Symfony, Doctrine',
            'yearsOfExperience' => 12,
            'achievements' => '',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        // Post
        $client->request('POST', '/api/backoffice/anonymous-cv', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Backend PHP / Symfony',
            'skills' => 'Symfony 7, Doctrine ORM, API Platform',
            'yearsOfExperience' => 12,
            'achievements' => "Conception d'une API multi-tenant.",
            'position' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Backend PHP / Symfony', $created['title']);
        self::assertSame(12, $created['yearsOfExperience']);
        self::assertIsInt($created['id']);
        $id = $created['id'];

        // GetCollection
        $client->request('GET', '/api/backoffice/anonymous-cv');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $collection);
        self::assertSame($id, $collection[0]['id']);

        // GetCollection filtrée par locale absente => vide
        $client->request('GET', '/api/backoffice/anonymous-cv?locale=en');
        self::assertResponseIsSuccessful();
        $emptyCollection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([], $emptyCollection);

        // Get
        $client->request('GET', sprintf('/api/backoffice/anonymous-cv/%d', $id));
        self::assertResponseIsSuccessful();

        // Put
        $client->request('PUT', sprintf('/api/backoffice/anonymous-cv/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Titre mis à jour',
            'skills' => 'Compétences mises à jour',
            'yearsOfExperience' => 13,
            'achievements' => 'Réalisations mises à jour.',
            'position' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Titre mis à jour', $updated['title']);
        self::assertSame(13, $updated['yearsOfExperience']);
        self::assertSame(1, $updated['position']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/anonymous-cv/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'X',
            'skills' => 'X',
            'yearsOfExperience' => 0,
            'achievements' => 'X',
            'position' => 0,
        ]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/anonymous-cv/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/anonymous-cv/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(AnonymousCvSectionRepositoryInterface::class)->findAll());
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
