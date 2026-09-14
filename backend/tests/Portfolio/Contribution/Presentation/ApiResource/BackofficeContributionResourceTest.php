<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Presentation\ApiResource;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/contributions, en
 * miroir de BackofficeAboutSiteCardResourceTest.
 */
final class BackofficeContributionResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

    /** UUID syntaxiquement valide mais absent de la base : 404 applicatif. */
    private const string UNKNOWN_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM contribution');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/contributions');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/contributions');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Spec 0003 D6 : `requirements: ['id' => Requirement::UUID]` fait d'un
     * segment malformé un 404 du **routeur** (RouterListener, priorité 32),
     * donc bien avant le firewall (8) et avant tout Provider. La preuve n'est
     * pas le code 404 seul — un 404 applicatif le porterait aussi — mais
     * l'absence de problem+json d'API Platform dans la réponse.
     */
    public function testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('GET', '/api/backoffice/contributions/1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * `Get /backoffice/contributions/{id}` : existant => 200 avec l'id en
     * chaîne RFC 4122, inconnu => 404 applicatif (celui du domaine, mappé en
     * problem+json — à distinguer du 404 du routeur couvert par
     * testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider).
     */
    public function testGetItemAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $contribution = $client->getContainer()->get(ContributionAdministratorInterface::class)->create(
            Locale::FR,
            'Retry de transport, re-prompt de validation',
            'symfony/ai',
            'Issue #1688',
            'https://github.com/symfony/ai/issues/1688',
            'Deux opérations sous un seul mot.',
            'Corps.',
            0,
        );

        $client->request('GET', sprintf('/api/backoffice/contributions/%s', $contribution->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($contribution->getId()->toRfc4122(), $item['id']);
        self::assertSame('Retry de transport, re-prompt de validation', $item['title']);

        $client->request('GET', '/api/backoffice/contributions/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testGetCollectionFiltersByLocaleQueryParameter(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $administrator = $client->getContainer()->get(ContributionAdministratorInterface::class);
        $administrator->create(Locale::FR, 'Titre FR', 'projet', 'ref', 'https://example.test', 'résumé', 'corps', 0);
        $administrator->create(Locale::EN, 'Title EN', 'project', 'ref', 'https://example.test', 'summary', 'body', 0);

        $client->request('GET', '/api/backoffice/contributions?locale=fr');
        self::assertResponseIsSuccessful();
        $filtered = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $filtered);
        self::assertSame('fr', $filtered[0]['locale']);

        $client->request('GET', '/api/backoffice/contributions');
        self::assertResponseIsSuccessful();
        $all = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $all);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        // Post
        $client->request('POST', '/api/backoffice/contributions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Retry de transport',
            'project' => 'symfony/ai',
            'reference' => 'Issue #1688',
            'url' => 'https://github.com/symfony/ai/issues/1688',
            'summary' => 'Résumé.',
            'body' => 'Corps.',
            'position' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Retry de transport', $created['title']);
        self::assertIsString($created['id']);
        self::assertTrue(Uuid::isValid($created['id']));
        $id = $created['id'];

        // Put
        $client->request('PUT', sprintf('/api/backoffice/contributions/%s', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Titre mis à jour',
            'project' => 'symfony/ai',
            'reference' => 'Issue #1688',
            'url' => 'https://github.com/symfony/ai/issues/1688',
            'summary' => 'Résumé mis à jour.',
            'body' => 'Corps mis à jour.',
            'position' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Titre mis à jour', $updated['title']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/contributions/'.self::UNKNOWN_ID, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'x',
            'project' => 'x',
            'reference' => 'x',
            'url' => 'https://example.test',
            'summary' => 'x',
            'body' => 'x',
            'position' => 0,
        ]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/contributions/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/contributions/%s', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(ContributionRepositoryInterface::class)->findAll());
    }

    public function testPostWithMissingLocaleIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/contributions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'title' => 'Retry de transport',
            'project' => 'symfony/ai',
            'reference' => 'Issue #1688',
            'url' => 'https://github.com/symfony/ai/issues/1688',
            'summary' => 'Résumé.',
            'body' => 'Corps.',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
