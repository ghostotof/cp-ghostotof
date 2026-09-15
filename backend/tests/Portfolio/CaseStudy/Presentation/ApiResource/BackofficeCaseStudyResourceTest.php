<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Presentation\ApiResource;

use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
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
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/case-studies, en
 * miroir du test du endpoint public (CaseStudyResourceTest) — même pattern
 * que BackofficeAboutSiteCardResourceTest.
 */
final class BackofficeCaseStudyResourceTest extends WebTestCase
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

        $client->request('GET', '/api/backoffice/case-studies/1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * `Get /backoffice/case-studies/{id}` : existant => 200 avec l'id en
     * chaîne RFC 4122, inconnu => 404 applicatif (celui du domaine, mappé en
     * problem+json — à distinguer du 404 du routeur couvert par
     * testANonUuidIdIsRejectedByTheRouterBeforeAnyProvider).
     */
    public function testGetItemAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $caseStudy = $client->getContainer()->get(CaseStudyAdministratorInterface::class)->create(
            Locale::FR,
            'Un cache mal isolé entre organisations',
            'Problème.',
            'Solution.',
            'Compromis.',
            'Résultat mesuré.',
        );

        $client->request('GET', sprintf('/api/backoffice/case-studies/%s', $caseStudy->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($caseStudy->getId()->toRfc4122(), $item['id']);
        self::assertSame('Un cache mal isolé entre organisations', $item['title']);

        $client->request('GET', '/api/backoffice/case-studies/'.self::UNKNOWN_ID);
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

        $administrator = $client->getContainer()->get(CaseStudyAdministratorInterface::class);
        $administrator->create(Locale::FR, 'Titre FR', 'p', 's', 't', 'r');
        $administrator->create(Locale::EN, 'Title EN', 'p', 's', 't', 'r');

        $client->request('GET', '/api/backoffice/case-studies?locale=fr');
        self::assertResponseIsSuccessful();
        $filtered = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $filtered);
        self::assertSame('fr', $filtered[0]['locale']);

        $client->request('GET', '/api/backoffice/case-studies');
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
        self::assertIsString($created['id']);
        self::assertTrue(Uuid::isValid($created['id']));
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
        $client->request('GET', sprintf('/api/backoffice/case-studies/%s', $id));
        self::assertResponseIsSuccessful();

        // Put
        $client->request('PUT', sprintf('/api/backoffice/case-studies/%s', $id), server: [
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
        // Spec 0004 D3 : la `position` du corps est ignorée — le contrat
        // d'écriture ne la porte plus, et l'entrée n'a pas bougé.
        self::assertSame(0, $updated['position']);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/case-studies/'.self::UNKNOWN_ID, server: [
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
        $client->request('DELETE', '/api/backoffice/case-studies/'.self::UNKNOWN_ID, server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/case-studies/%s', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], self::getContainer()->get(CaseStudyRepositoryInterface::class)->findAll());
    }

    /**
     * Spec 0004 D3 : la position n'est plus jamais saisie. Une entrée sans
     * groupe se range après la dernière du périmètre — toutes langues
     * confondues, puisque les traductions d'un contenu partagent sa position.
     */
    public function testPostWithoutTranslationGroupGoesToTheEndOfTheScope(): void
    {
        [$client, $csrfToken] = $this->superClient();

        $existing = $this->administrator($client)->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');
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
        $administrator->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');
        $second = $administrator->create(Locale::FR, 'Second', 'p', 's', 't', 'r');
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

        $existing = $this->administrator($client)->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');

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
        $administrator->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');
        $french = $administrator->create(Locale::FR, 'Second', 'p', 's', 't', 'r');
        $english = $administrator->create(Locale::EN, 'Second, in English', 'p', 's', 't', 'r', $french->getTranslationGroup());

        $updated = $this->put($client, $csrfToken, $french->getId()->toRfc4122(), [
            'title' => 'Second, détaché',
            'translationGroup' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $updated['position']);
        self::assertNotSame($english->getTranslationGroup()->toRfc4122(), $updated['translationGroup']);

        $reloaded = self::getContainer()->get(CaseStudyRepositoryInterface::class)->findOneById($english->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getPosition());
    }

    public function testPutWithAnotherGroupAttachesAndInheritsItsPosition(): void
    {
        [$client, $csrfToken] = $this->superClient();

        $administrator = $this->administrator($client);
        $target = $administrator->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');
        $english = $administrator->create(Locale::EN, 'Orpheline', 'p', 's', 't', 'r');
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
        $target = $administrator->create(Locale::FR, 'Premier', 'p', 's', 't', 'r');
        $other = $administrator->create(Locale::FR, 'Second', 'p', 's', 't', 'r');

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

    private function administrator(KernelBrowser $client): CaseStudyAdministratorInterface
    {
        return $client->getContainer()->get(CaseStudyAdministratorInterface::class);
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
        $client->request('POST', '/api/backoffice/case-studies', server: [
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
        $client->request('PUT', sprintf('/api/backoffice/case-studies/%s', $id), server: [
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
            'problem' => 'Problème.',
            'solution' => 'Solution.',
            'tradeoffs' => 'Compromis.',
            'measuredResult' => 'Résultat.',
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
