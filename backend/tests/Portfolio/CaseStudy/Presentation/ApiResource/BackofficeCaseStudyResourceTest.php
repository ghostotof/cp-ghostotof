<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Presentation\ApiResource;

use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/case-studies, en
 * miroir du test du endpoint public (CaseStudyResourceTest) — même pattern
 * que BackofficeExperienceTechnologyResourceTest.
 */
final class BackofficeCaseStudyResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM case_study');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/case-studies');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/case-studies');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        // Post
        $client->request('POST', '/api/backoffice/case-studies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Un cache mal isolé entre organisations',
            'problem' => 'Fuite occasionnelle de données entre tenants sous forte charge.',
            'solution' => 'Clé de cache incluant systématiquement l\'identifiant de tenant.',
            'tradeoffs' => 'Complexité de clé accrue.',
            'measuredResult' => 'Zéro fuite sur 3 mois de production.',
            'position' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Un cache mal isolé entre organisations', $created['title']);
        self::assertIsInt($created['id']);
        $id = $created['id'];

        // GetCollection
        $client->request('GET', '/api/backoffice/case-studies');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $collection);
        self::assertSame($id, $collection[0]['id']);

        // GetCollection filtrée par locale absente => vide
        $client->request('GET', '/api/backoffice/case-studies?locale=en');
        self::assertResponseIsSuccessful();
        $emptyCollection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([], $emptyCollection);

        // Get
        $client->request('GET', sprintf('/api/backoffice/case-studies/%d', $id));
        self::assertResponseIsSuccessful();

        // Put
        $client->request('PUT', sprintf('/api/backoffice/case-studies/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Titre mis à jour',
            'problem' => 'Problème mis à jour.',
            'solution' => 'Solution mise à jour.',
            'tradeoffs' => 'Compromis mis à jour.',
            'measuredResult' => 'Résultat mis à jour.',
            'position' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Titre mis à jour', $updated['title']);
        self::assertSame(1, $updated['position']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/case-studies/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'X',
            'problem' => 'X',
            'solution' => 'X',
            'tradeoffs' => 'X',
            'measuredResult' => 'X',
            'position' => 0,
        ]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/case-studies/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/case-studies/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(CaseStudyRepositoryInterface::class)->findAll());
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
