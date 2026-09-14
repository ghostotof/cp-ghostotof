<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Presentation\ApiResource;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/experience/technologies,
 * en miroir du test du endpoint public (ExperienceTechnologyResourceTest).
 */
final class BackofficeExperienceTechnologyResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM experience_technology');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/experience/technologies');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/experience/technologies');

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

        $client->request('GET', '/api/backoffice/experience/technologies/1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * `Get /backoffice/experience/technologies/{id}` : existant => 200 avec
     * l'id en chaîne RFC 4122, inconnu => 404 applicatif (celui du domaine,
     * mappé en problem+json — à distinguer du 404 du routeur couvert par
     * testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider).
     */
    public function testGetItemAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $technology = $client->getContainer()->get(ExperienceTechnologyRegistrarInterface::class)->register('Docker', 6.5, 'docker', null);

        $client->request('GET', sprintf('/api/backoffice/experience/technologies/%s', $technology->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($technology->getId()->toRfc4122(), $item['id']);
        self::assertSame('Docker', $item['name']);

        $client->request('GET', '/api/backoffice/experience/technologies/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->getContainer()->get(ExperienceTechnologyRegistrarInterface::class)->register('Docker', 6.5, 'docker', null);

        // GetCollection
        $client->request('GET', '/api/backoffice/experience/technologies');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $collection);
        self::assertSame('Docker', $collection[0]['name']);
        $id = $collection[0]['id'];
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));

        // Post
        $client->request('POST', '/api/backoffice/experience/technologies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['name' => 'PHP', 'years' => 13.5, 'iconKey' => 'php', 'relatedTechnologyName' => null]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('PHP', $created['name']);
        self::assertIsString($created['id']);
        self::assertTrue(Uuid::isValid($created['id']));

        // Post - collision de nom => 409
        $client->request('POST', '/api/backoffice/experience/technologies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['name' => 'PHP', 'years' => 1.0]));
        self::assertResponseStatusCodeSame(409);

        // Put
        $client->request('PUT', sprintf('/api/backoffice/experience/technologies/%s', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['name' => 'Docker', 'years' => 7.0, 'iconKey' => 'docker', 'relatedTechnologyName' => null]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(7.0, $updated['years']);

        // Put - collision de nom avec une autre techno => 409
        $client->request('PUT', sprintf('/api/backoffice/experience/technologies/%s', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['name' => 'PHP', 'years' => 7.0]));
        self::assertResponseStatusCodeSame(409);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/experience/technologies/'.self::UNKNOWN_ID, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['name' => 'Rust', 'years' => 1.0]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/experience/technologies/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/experience/technologies/%s', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        // La liste publique ne reflète plus la techno supprimée
        $client->request('GET', '/api/experience/technologies');
        $publicList = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['PHP'], array_column($publicList, 'name'));
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
