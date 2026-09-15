<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Presentation\ApiResource;

use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
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
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/anonymous-cv, en
 * miroir du test du endpoint public (AnonymousCvSectionResourceTest) — même
 * pattern que BackofficeCaseStudyResourceTest et BackofficeAboutSiteCardResourceTest.
 */
final class BackofficeAnonymousCvSectionResourceTest extends WebTestCase
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

        $client->request('GET', '/api/backoffice/anonymous-cv/1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * `Get /backoffice/anonymous-cv/{id}` : existant => 200 avec l'id en
     * chaîne RFC 4122, inconnu => 404 applicatif (celui du domaine, mappé en
     * problem+json — à distinguer du 404 du routeur couvert par
     * testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider).
     */
    public function testGetItemAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $section = $client->getContainer()->get(AnonymousCvSectionAdministratorInterface::class)->create(
            Locale::FR,
            'Backend PHP / Symfony',
            'Symfony, Doctrine',
            12,
            "Conception d'une API multi-tenant.",
        );

        $client->request('GET', sprintf('/api/backoffice/anonymous-cv/%s', $section->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($section->getId()->toRfc4122(), $item['id']);
        self::assertSame('Backend PHP / Symfony', $item['title']);

        $client->request('GET', '/api/backoffice/anonymous-cv/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
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
        self::assertIsString($created['id']);
        self::assertTrue(Uuid::isValid($created['id']));
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
        $client->request('GET', sprintf('/api/backoffice/anonymous-cv/%s', $id));
        self::assertResponseIsSuccessful();

        // Put
        $client->request('PUT', sprintf('/api/backoffice/anonymous-cv/%s', $id), server: [
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
        // Spec 0004 D3 : la `position` du corps est ignorée — le contrat
        // d'écriture ne la porte plus, et l'entrée n'a pas bougé.
        self::assertSame(0, $updated['position']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/anonymous-cv/'.self::UNKNOWN_ID, server: [
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
        $client->request('DELETE', '/api/backoffice/anonymous-cv/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/anonymous-cv/%s', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(AnonymousCvSectionRepositoryInterface::class)->findAll());
    }

    /**
     * Spec 0004 D3 : la position n'est plus jamais saisie. Une entrée sans
     * groupe se range après la dernière du périmètre — toutes langues
     * confondues, puisque les traductions d'un contenu partagent sa position.
     */
    public function testPostWithoutTranslationGroupGoesToTheEndOfTheScope(): void
    {
        [$client, $csrfToken] = $this->superClient();

        $existing = $this->administrator($client)->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');
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
        [$client, $csrfToken] = $this->superClient();

        $administrator = $this->administrator($client);
        $administrator->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');
        $second = $administrator->create(Locale::FR, 'Second', 'Doctrine', 10, 'Réalisations.');
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
        [$client, $csrfToken] = $this->superClient();

        $existing = $this->administrator($client)->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');

        $body = $this->post($client, $csrfToken, [
            'title' => 'Doublon',
            'translationGroup' => $existing->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/errors/translation-already-exists', $body['type'] ?? null);
    }

    public function testPostWithAnUnknownTranslationGroupIsRejected(): void
    {
        [$client, $csrfToken] = $this->superClient();

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
        [$client, $csrfToken] = $this->superClient();

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
        [$client, $csrfToken] = $this->superClient();

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
        [$client, $csrfToken] = $this->superClient();

        $administrator = $this->administrator($client);
        $administrator->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');
        $french = $administrator->create(Locale::FR, 'Second', 'Doctrine', 10, 'Réalisations.');
        $english = $administrator->create(Locale::EN, 'Second, in English', 'Doctrine', 10, 'Achievements.', $french->getTranslationGroup());

        $updated = $this->put($client, $csrfToken, $french->getId()->toRfc4122(), [
            'title' => 'Second, détaché',
            'translationGroup' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $updated['position']);
        self::assertNotSame($english->getTranslationGroup()->toRfc4122(), $updated['translationGroup']);

        $reloaded = self::getContainer()->get(AnonymousCvSectionRepositoryInterface::class)->findOneById($english->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getPosition());
    }

    public function testPutWithAnotherGroupAttachesAndInheritsItsPosition(): void
    {
        [$client, $csrfToken] = $this->superClient();

        $administrator = $this->administrator($client);
        $target = $administrator->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');
        $english = $administrator->create(Locale::EN, 'Orpheline', 'Doctrine', 10, 'Achievements.');
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
        [$client, $csrfToken] = $this->superClient();

        $administrator = $this->administrator($client);
        $target = $administrator->create(Locale::FR, 'Premier', 'Symfony', 12, 'Réalisations.');
        $other = $administrator->create(Locale::FR, 'Second', 'Doctrine', 10, 'Réalisations.');

        $body = $this->put($client, $csrfToken, $other->getId()->toRfc4122(), [
            'title' => 'Second',
            'translationGroup' => $target->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/errors/translation-already-exists', $body['type'] ?? null);
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function superClient(): array
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);

        return [$client, $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword())];
    }

    private function administrator(KernelBrowser $client): AnonymousCvSectionAdministratorInterface
    {
        return $client->getContainer()->get(AnonymousCvSectionAdministratorInterface::class);
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
        $client->request('POST', '/api/backoffice/anonymous-cv', server: [
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
        $client->request('PUT', sprintf('/api/backoffice/anonymous-cv/%s', $id), server: [
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
            'skills' => 'Symfony, Doctrine',
            'yearsOfExperience' => 12,
            'achievements' => 'Réalisations.',
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
