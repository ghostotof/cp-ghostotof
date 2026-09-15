<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Presentation\ApiResource;

use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
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
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/incidents, en miroir de
 * BackofficeAboutSiteCardResourceTest.
 */
final class BackofficeIncidentResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM incident');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/incidents');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/backoffice/incidents');

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

        $client->request('GET', '/api/backoffice/incidents/1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * `Get /backoffice/incidents/{id}` : existant => 200 avec l'id en chaîne
     * RFC 4122, inconnu => 404 applicatif (celui du domaine, mappé en
     * problem+json — à distinguer du 404 du routeur couvert par
     * testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider).
     */
    public function testGetItemAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $incident = $client->getContainer()->get(IncidentAdministratorInterface::class)->create(
            Locale::FR,
            'RabbitMQ en CrashLoopBackOff',
            'v0.5.0',
            new \DateTimeImmutable('2026-09-03'),
            'Impact.',
            'Cause.',
            'Résolution.',
            'Invariant.',
        );

        $client->request('GET', sprintf('/api/backoffice/incidents/%s', $incident->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($incident->getId()->toRfc4122(), $item['id']);
        self::assertSame('RabbitMQ en CrashLoopBackOff', $item['title']);

        $client->request('GET', '/api/backoffice/incidents/'.self::UNKNOWN_ID);
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

        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);
        $administrator->create(Locale::FR, 'Titre FR', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $administrator->create(Locale::EN, 'Title EN', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');

        $client->request('GET', '/api/backoffice/incidents?locale=fr');
        self::assertResponseIsSuccessful();
        $filtered = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $filtered);
        self::assertSame('fr', $filtered[0]['locale']);

        $client->request('GET', '/api/backoffice/incidents');
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
        $client->request('POST', '/api/backoffice/incidents', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'RabbitMQ en CrashLoopBackOff',
            'version' => 'v0.5.0',
            'occurredAt' => '2026-09-03',
            'impact' => 'Impact.',
            'rootCause' => 'Cause.',
            'resolution' => 'Résolution.',
            'invariant' => 'Invariant.',
            'position' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('RabbitMQ en CrashLoopBackOff', $created['title']);
        self::assertIsString($created['id']);
        self::assertTrue(Uuid::isValid($created['id']));
        $id = $created['id'];

        // Put
        $client->request('PUT', sprintf('/api/backoffice/incidents/%s', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Titre mis à jour',
            'version' => 'v0.6.0',
            'occurredAt' => '2026-09-10',
            'impact' => 'Nouvel impact.',
            'rootCause' => 'Nouvelle cause.',
            'resolution' => 'Nouvelle résolution.',
            'invariant' => 'Nouvel invariant.',
            'position' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Titre mis à jour', $updated['title']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/incidents/'.self::UNKNOWN_ID, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'x',
            'version' => 'x',
            'occurredAt' => '2026-01-01',
            'impact' => 'x',
            'rootCause' => 'x',
            'resolution' => 'x',
            'invariant' => 'x',
            'position' => 0,
        ]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/incidents/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/incidents/%s', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(IncidentRepositoryInterface::class)->findAll());
    }

    public function testPostWithMissingInvariantIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/incidents', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'locale' => 'fr',
            'title' => 'Titre',
            'version' => 'v0.1.0',
            'occurredAt' => '2026-01-01',
            'impact' => 'i',
            'rootCause' => 'c',
            'resolution' => 'r',
            'invariant' => '',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Spec 0004 D3 : la position n'est plus jamais saisie. Une entrée sans
     * groupe se range après la dernière du périmètre — toutes langues
     * confondues, puisque les traductions d'un contenu partagent sa position.
     */
    public function testPostWithoutTranslationGroupGoesToTheEndOfTheScope(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $existing = $client->getContainer()->get(IncidentAdministratorInterface::class)->create(
            Locale::FR,
            'Premier',
            'v0.1.0',
            new \DateTimeImmutable('2026-01-01'),
            'i',
            'c',
            'r',
            'inv',
        );
        self::assertSame(0, $existing->getPosition());

        $created = $this->post($client, $csrfToken, ['title' => 'Second']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $created['position']);
        self::assertIsString($created['translationGroup']);
        self::assertNotSame($existing->getTranslationGroup()->toRfc4122(), $created['translationGroup']);
    }

    /**
     * Le cœur de D3 : la version anglaise d'un contenu n'a pas de position à
     * elle, elle prend celle du contenu. C'est ce qui fera qu'un déplacement
     * suivra le contenu quelle que soit la langue (D5).
     */
    public function testPostWithTheGroupOfAnotherLocaleInheritsItsPosition(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);
        $administrator->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $second = $administrator->create(Locale::FR, 'Second', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv');
        self::assertSame(1, $second->getPosition());

        $created = $this->post($client, $csrfToken, [
            'locale' => 'en',
            'title' => 'Second, in English',
            'translationGroup' => $second->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($second->getTranslationGroup()->toRfc4122(), $created['translationGroup']);
        self::assertSame(1, $created['position']);
    }

    /**
     * Un groupe porte au plus une entrée par langue (index unique
     * (translation_group, locale)). La vérification a lieu avant l'écriture :
     * l'auteur reçoit un 409 lisible, pas la violation de contrainte en 500.
     */
    public function testPostWithAGroupThatAlreadyCarriesTheLocaleIsAConflict(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $existing = $client->getContainer()->get(IncidentAdministratorInterface::class)
            ->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');

        $body = $this->post($client, $csrfToken, [
            'title' => 'Doublon',
            'translationGroup' => $existing->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/errors/translation-already-exists', $body['type'] ?? null);
    }

    public function testPostWithAnUnknownTranslationGroupIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $body = $this->post($client, $csrfToken, [
            'title' => 'Orpheline',
            'translationGroup' => self::UNKNOWN_ID,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-translation-group', $body['type'] ?? null);
    }

    /**
     * `#[Assert\Uuid]` borne le champ en amont : une chaîne qui n'est pas un
     * UUID n'atteint jamais le domaine.
     */
    public function testPostWithAMalformedTranslationGroupIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $this->post($client, $csrfToken, ['title' => 'x', 'translationGroup' => 'pas-un-uuid']);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Spec 0004 D3 : `position` a quitté le contrat d'écriture. Un corps qui en
     * porte encore une — le formulaire actuel, tant que B5 n'est pas livré —
     * est accepté et ignoré, jamais refusé.
     */
    public function testAPositionInTheBodyIsIgnored(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $created = $this->post($client, $csrfToken, ['title' => 'Position imposée', 'position' => 99]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $created['position']);
    }

    /**
     * `PUT` avec `translationGroup: null` : l'entrée est séparée de ses
     * traductions — groupe neuf — et part en fin de périmètre (issue #169 :
     * la laisser en place laissait deux entrées d'une même langue sur une
     * position dès que l'ancien groupe la recevait à nouveau). Sa traduction,
     * elle, n'est pas touchée.
     */
    public function testPutWithANullTranslationGroupDetachesAndMovesToTheEnd(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);
        $administrator->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $french = $administrator->create(Locale::FR, 'Second', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv');
        $english = $administrator->create(Locale::EN, 'Second, in English', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv', $french->getTranslationGroup());

        $updated = $this->put($client, $csrfToken, $french->getId()->toRfc4122(), [
            'title' => 'Second, détaché',
            'translationGroup' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $updated['position']);
        self::assertNotSame($english->getTranslationGroup()->toRfc4122(), $updated['translationGroup']);

        $repository = self::getContainer()->get(IncidentRepositoryInterface::class);
        $reloaded = $repository->findOneById($english->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getPosition());
    }

    public function testPutWithAnotherGroupAttachesAndInheritsItsPosition(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);
        $target = $administrator->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $english = $administrator->create(Locale::EN, 'Orpheline', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv');
        self::assertSame(1, $english->getPosition());

        $updated = $this->put($client, $csrfToken, $english->getId()->toRfc4122(), [
            'locale' => 'en',
            'title' => 'Premier, in English',
            'translationGroup' => $target->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($target->getTranslationGroup()->toRfc4122(), $updated['translationGroup']);
        self::assertSame(0, $updated['position']);
    }

    public function testPutTowardsAGroupThatAlreadyCarriesTheLocaleIsAConflict(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);
        $target = $administrator->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $other = $administrator->create(Locale::FR, 'Second', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv');

        $body = $this->put($client, $csrfToken, $other->getId()->toRfc4122(), [
            'title' => 'Second',
            'translationGroup' => $target->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/errors/translation-already-exists', $body['type'] ?? null);
    }

    /**
     * Le corps décodé est du `mixed` : ce sont les assertions PHPUnit qui
     * valident la forme de la réponse, pas l'analyse statique (cf. les
     * `ignoreErrors` de `phpstan.dist.neon`, portée `tests/` seulement).
     *
     * @param array<string, mixed> $overrides
     */
    private function post(KernelBrowser $client, string $csrfToken, array $overrides): mixed
    {
        $client->request('POST', '/api/backoffice/incidents', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($overrides + $this->payload()));

        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Le corps décodé est du `mixed` : ce sont les assertions PHPUnit qui
     * valident la forme de la réponse, pas l'analyse statique (cf. les
     * `ignoreErrors` de `phpstan.dist.neon`, portée `tests/` seulement).
     *
     * @param array<string, mixed> $overrides
     */
    private function put(KernelBrowser $client, string $csrfToken, string $id, array $overrides): mixed
    {
        $client->request('PUT', sprintf('/api/backoffice/incidents/%s', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($overrides + $this->payload()));

        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'locale' => 'fr',
            'title' => 'Titre',
            'version' => 'v0.1.0',
            'occurredAt' => '2026-01-01',
            'impact' => 'i',
            'rootCause' => 'c',
            'resolution' => 'r',
            'invariant' => 'inv',
        ];
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
