<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\ApiResource;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec 0004 B4 : PUT /api/backoffice/about/me-cards/order. Même grille que
 * BackofficeIncidentOrderResourceTest, plus le seul cas propre à cette
 * ressource : son périmètre d'ordre est la **catégorie**, pas la table. D'où
 * le champ `category` dans le corps, et le test qui prouve que réordonner
 * `technical` laisse `hobby` et `personal` exactement où ils étaient.
 */
final class BackofficeAboutMeCardOrderResourceTest extends WebTestCase
{
    use HttpJson;

    private const string ORDER_PATH = '/api/backoffice/about/me-cards/order';
    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

    /** UUID syntaxiquement valide mais absent du périmètre. */
    private const string UNKNOWN_KEY = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM about_me_card');
        $connection->executeStatement('DELETE FROM about_settings');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testReorderingIsReflectedOnTheBackofficeCollectionAndBothPublicEndpoints(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedTechnicalCards($client);

        $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [$second, $first]]);

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/backoffice/about/me-cards?locale=fr');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($collection);
        // La collection ?locale=fr est servie triée par position, toutes
        // catégories confondues — et les positions se renumérotent par
        // catégorie, donc plusieurs cartes peuvent porter la même. On isole la
        // catégorie réordonnée avant de lire sa séquence.
        $technical = array_values(array_filter(
            $collection,
            static fn (mixed $card): bool => \is_array($card) && 'technical' === ($card['category'] ?? null),
        ));
        self::assertSame([$second, $first], array_column($technical, 'translationGroup'));
        self::assertSame([0, 1], array_column($technical, 'position'));

        $client->request('GET', '/api/about/fr');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second', 'Premier'], array_column($payload['me']['technicalCards'], 'title'));

        $client->request('GET', '/api/about/en');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second, in English', 'First'], array_column($payload['me']['technicalCards'], 'title'));
    }

    /**
     * Le cas propre à cette ressource. Les trois catégories se numérotent
     * séparément — chacune a son tableau sur la page — donc réordonner l'une
     * ne doit toucher ni les positions ni les groupes des deux autres.
     */
    public function testReorderingTechnicalLeavesHobbyAndPersonalUntouched(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedTechnicalCards($client);
        $this->seedOtherCategories($client);

        $before = $this->positionsOutsideTechnical();

        $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [$second, $first]]);

        self::assertResponseStatusCodeSame(204);
        self::assertNotEmpty($before);
        self::assertSame($before, $this->positionsOutsideTechnical());
    }

    /**
     * La règle d'ensemble exact s'applique **à la catégorie** : le groupe d'une
     * carte « hobby » n'est pas du périmètre de `technical`, même s'il existe
     * bel et bien en base.
     */
    public function testAGroupFromAnotherCategoryIsUnknownToThisScope(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedTechnicalCards($client);
        $hobby = $this->seedOtherCategories($client);

        $body = $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [$second, $first, $hobby]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-order-entry', $body['type'] ?? null);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedTechnicalCards($client);

        $body = $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [$second, $first, self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-order-entry', $body['type'] ?? null);
    }

    public function testAMissingKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first] = $this->seedTwoTranslatedTechnicalCards($client);

        $body = $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [$first]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/incomplete-order', $body['type'] ?? null);
    }

    /**
     * 403 et non 401 : sur une mutation, le double-submit CSRF
     * (CsrfCookieRequestSubscriber, priorité 20) tranche avant le firewall.
     */
    public function testAnonymousRequestIsForbidden(): void
    {
        $client = self::createClient();

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['category' => 'technical', 'groups' => [self::UNKNOWN_KEY]]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testBaseTierTokenIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedAccountWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->putOrder($client, $csrfToken, ['category' => 'technical', 'groups' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleSuperWithoutTheCsrfHeaderIsForbidden(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedTechnicalCards($client);

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['category' => 'technical', 'groups' => [$second, $first]]));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Les refus que la validation du DTO tranche avant le domaine. Une
     * catégorie absente en fait partie : elle échoue en 422 plutôt que de
     * réordonner une catégorie choisie par défaut.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejectedByValidation(array $payload): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $this->seedTwoTranslatedTechnicalCards($client);

        $this->putOrder($client, $csrfToken, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'liste vide' => [['category' => 'technical', 'groups' => []]];
        yield 'champ groups absent' => [['category' => 'technical']];
        yield 'clé non-UUID' => [['category' => 'technical', 'groups' => ['pas-un-uuid']]];
        yield 'doublon' => [['category' => 'technical', 'groups' => [self::UNKNOWN_KEY, self::UNKNOWN_KEY]]];
        yield 'clé non textuelle' => [['category' => 'technical', 'groups' => [['imbriqué']]]];
        yield 'catégorie absente' => [['groups' => [self::UNKNOWN_KEY]]];
        yield 'catégorie inconnue' => [['category' => 'professional', 'groups' => [self::UNKNOWN_KEY]]];
    }

    /**
     * Deux cartes « technical », chacune en FR et en EN, plus les réglages des
     * deux locales sans lesquels la page publique répondrait 404.
     *
     * @return array{string, string} les groupes, dans leur ordre initial
     */
    private function seedTwoTranslatedTechnicalCards(KernelBrowser $client): array
    {
        $settingsRepository = $client->getContainer()->get(AboutSettingsRepositoryInterface::class);
        $settingsRepository->save(new AboutSettings(Locale::FR, 'Ce site', 'Moi', 'Techniquement', 'Humainement', 'En dehors'));
        $settingsRepository->save(new AboutSettings(Locale::EN, 'This site', 'Me', 'Technically', 'Personally', 'Outside work'));

        $administrator = $client->getContainer()->get(AboutMeCardAdministratorInterface::class);

        $first = $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Premier', 'description', 'boxes');
        $second = $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Second', 'description', 'boxes');
        $administrator->create(Locale::EN, AboutMeCardCategory::TECHNICAL, 'First', 'description', 'boxes', $first->getTranslationGroup());
        $administrator->create(Locale::EN, AboutMeCardCategory::TECHNICAL, 'Second, in English', 'description', 'boxes', $second->getTranslationGroup());

        self::assertSame(0, $first->getPosition());
        self::assertSame(1, $second->getPosition());

        return [$first->getTranslationGroup()->toRfc4122(), $second->getTranslationGroup()->toRfc4122()];
    }

    /**
     * Deux cartes « hobby » et une « personal », dans les deux langues : de
     * quoi rendre visible tout débordement du périmètre.
     *
     * @return string le groupe d'une carte « hobby »
     */
    private function seedOtherCategories(KernelBrowser $client): string
    {
        $administrator = $client->getContainer()->get(AboutMeCardAdministratorInterface::class);

        $hobby = $administrator->create(Locale::FR, AboutMeCardCategory::HOBBY, 'Loisir', 'description', null);
        $administrator->create(Locale::EN, AboutMeCardCategory::HOBBY, 'Hobby', 'description', null, $hobby->getTranslationGroup());
        $administrator->create(Locale::FR, AboutMeCardCategory::HOBBY, 'Autre loisir', 'description', null);
        $administrator->create(Locale::FR, AboutMeCardCategory::PERSONAL, 'Humain', 'description', null);

        return $hobby->getTranslationGroup()->toRfc4122();
    }

    /**
     * Position de chaque carte des deux autres catégories, relue en base et
     * indexée par id : la comparaison avant/après porte donc sur les lignes
     * elles-mêmes, pas sur un agrégat qui pourrait masquer une permutation.
     *
     * @return array<string, int>
     */
    private function positionsOutsideTechnical(): array
    {
        $repository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        $positions = [];
        foreach ([AboutMeCardCategory::HOBBY, AboutMeCardCategory::PERSONAL] as $category) {
            foreach ($repository->findByCategory($category) as $card) {
                $positions[$card->getId()->toRfc4122()] = $card->getPosition();
            }
        }
        ksort($positions);

        return $positions;
    }

    /**
     * Le corps décodé est du `mixed` : ce sont les assertions PHPUnit qui
     * valident la forme de la réponse, pas l'analyse statique (cf. les
     * `ignoreErrors` de `phpstan.dist.neon`, portée `tests/` seulement).
     *
     * @param array<string, mixed> $payload
     */
    private function putOrder(KernelBrowser $client, string $csrfToken, array $payload): mixed
    {
        $client->request('PUT', self::ORDER_PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($payload));

        $content = (string) $client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function registerSuperAdmin(KernelBrowser $client): void
    {
        $client->getContainer()->get(CpgUserRegistrarInterface::class)
            ->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
    }

    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
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
