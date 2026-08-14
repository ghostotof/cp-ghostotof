<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Presentation\ApiResource;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/experience/technologies,
 * en miroir du test du endpoint public (ExperienceTechnologyResourceTest).
 */
final class BackofficeExperienceTechnologyResourceTest extends WebTestCase
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
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/experience/technologies');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $client->getContainer()->get(ExperienceTechnologyRegistrarInterface::class)->register('Docker', 6.5, 'docker', null);

        // GetCollection
        $client->request('GET', '/api/backoffice/experience/technologies');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $collection);
        self::assertSame('Docker', $collection[0]['name']);
        $id = $collection[0]['id'];
        self::assertIsInt($id);

        // Post
        $client->request('POST', '/api/backoffice/experience/technologies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['name' => 'PHP', 'years' => 13.5, 'iconKey' => 'php', 'relatedTechnologyName' => null]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('PHP', $created['name']);
        self::assertIsInt($created['id']);

        // Post - collision de nom => 409
        $client->request('POST', '/api/backoffice/experience/technologies', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['name' => 'PHP', 'years' => 1.0]));
        self::assertResponseStatusCodeSame(409);

        // Put
        $client->request('PUT', sprintf('/api/backoffice/experience/technologies/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['name' => 'Docker', 'years' => 7.0, 'iconKey' => 'docker', 'relatedTechnologyName' => null]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(7.0, $updated['years']);

        // Put - collision de nom avec une autre techno => 409
        $client->request('PUT', sprintf('/api/backoffice/experience/technologies/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['name' => 'PHP', 'years' => 7.0]));
        self::assertResponseStatusCodeSame(409);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/experience/technologies/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: json_encode(['name' => 'Rust', 'years' => 1.0]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/experience/technologies/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/experience/technologies/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        // La liste publique ne reflète plus la techno supprimée
        $client->request('GET', '/api/experience/technologies');
        $publicList = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['PHP'], array_column($publicList, 'name'));
    }

    private function loginAs(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $username, string $password): string
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
