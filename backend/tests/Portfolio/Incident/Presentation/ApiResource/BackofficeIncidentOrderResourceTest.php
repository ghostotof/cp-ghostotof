<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Presentation\ApiResource;

use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
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
 * Spec 0004 B4 : PUT /api/backoffice/incidents/order. Représentant des neuf
 * ressources d'ordre — les huit autres reprennent la même grille, seuls le
 * chemin, le périmètre et le nom de la clé changent.
 *
 * Deux choses s'y vérifient et une seule ne suffirait pas :
 * la collection de backoffice rend le nouvel ordre, et **les deux endpoints
 * publics** aussi. Un ordre juste en base mais invisible côté visiteur ne
 * serait pas la fonctionnalité demandée ; un ordre qui divergerait entre le FR
 * et l'EN contredirait D5 (« un déplacement suit le contenu quelle que soit la
 * langue »).
 */
final class BackofficeIncidentOrderResourceTest extends WebTestCase
{
    use HttpJson;

    private const string ORDER_PATH = '/api/backoffice/incidents/order';
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
        $connection->executeStatement('DELETE FROM incident');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * Le cas nominal, relu aux trois endroits qui comptent.
     */
    public function testReorderingIsReflectedOnTheBackofficeCollectionAndBothPublicEndpoints(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedIncidents($client);

        $client->request('PUT', self::ORDER_PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['groups' => [$second, $first]]));

        self::assertResponseStatusCodeSame(204);

        // `output: false` : la réponse ne porte rien. Relu dans une variable
        // plutôt qu'en ligne — une assertion sur l'appel lui-même rétrécirait
        // le type de retour de getContent() pour tout le reste de la méthode.
        $emptyBody = $client->getResponse()->getContent();
        self::assertSame('', $emptyBody);

        $client->request('GET', '/api/backoffice/incidents?locale=fr');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($collection);
        // La collection ?locale=fr est servie triée par position : la séquence
        // des groupes et leurs positions disent la même chose de deux façons.
        self::assertSame([$second, $first], array_column($collection, 'translationGroup'));
        self::assertSame([0, 1], array_column($collection, 'position'));

        $client->request('GET', '/api/incidents/fr');
        self::assertResponseIsSuccessful();
        $french = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second', 'Premier'], array_column($french, 'title'));

        $client->request('GET', '/api/incidents/en');
        self::assertResponseIsSuccessful();
        $english = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second, in English', 'First'], array_column($english, 'title'));
    }

    /**
     * Spec 0004 D4 : une clé inconnue ne se range pas silencieusement, elle
     * signale que la liste envoyée ne décrit plus le périmètre du serveur.
     */
    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedIncidents($client);

        $body = $this->putOrder($client, $csrfToken, ['groups' => [$second, $first, self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-order-entry', $body['type'] ?? null);
    }

    /**
     * L'autre moitié de la règle d'ensemble exact : une entrée du périmètre
     * absente de la liste est un refus, pas une entrée reléguée à la fin.
     */
    public function testAMissingKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first] = $this->seedTwoTranslatedIncidents($client);

        $body = $this->putOrder($client, $csrfToken, ['groups' => [$first]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/incomplete-order', $body['type'] ?? null);
    }

    /**
     * 403 et non 401 : sur une mutation, le double-submit CSRF
     * (CsrfCookieRequestSubscriber, priorité 20) tranche avant même que le
     * firewall n'ait à constater l'absence de jeton.
     */
    public function testAnonymousRequestIsForbidden(): void
    {
        $client = self::createClient();

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['groups' => [self::UNKNOWN_KEY]]));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * ADR 0003 : le palier de base porte du contenu publiable, jamais
     * l'administration de ce contenu.
     */
    public function testBaseTierTokenIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->putOrder($client, $csrfToken, ['groups' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedAccountWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->putOrder($client, $csrfToken, ['groups' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleSuperWithoutTheCsrfHeaderIsForbidden(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedIncidents($client);

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['groups' => [$second, $first]]));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Les trois refus que la validation du DTO tranche avant le domaine. Le
     * dernier — une valeur qui n'est même pas une chaîne — mérite son cas :
     * `Assert\Uuid` lève une UnexpectedValueException (500) sur un tableau, ce
     * que `Assert\Sequentially` évite en s'arrêtant au `Type`.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejectedByValidation(array $payload): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $this->seedTwoTranslatedIncidents($client);

        $this->putOrder($client, $csrfToken, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'liste vide' => [['groups' => []]];
        yield 'champ absent' => [[]];
        yield 'clé non-UUID' => [['groups' => ['pas-un-uuid']]];
        yield 'doublon' => [['groups' => [self::UNKNOWN_KEY, self::UNKNOWN_KEY]]];
        yield 'clé non textuelle' => [['groups' => [['imbriqué']]]];
    }

    /**
     * Deux contenus, chacun en FR et en EN : c'est la seule forme qui permette
     * de vérifier que l'ordre suit le contenu et non la ligne.
     *
     * @return array{string, string} les groupes, dans leur ordre initial
     */
    private function seedTwoTranslatedIncidents(KernelBrowser $client): array
    {
        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);

        $first = $administrator->create(Locale::FR, 'Premier', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv');
        $second = $administrator->create(Locale::FR, 'Second', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv');
        $administrator->create(Locale::EN, 'First', 'v0.1.0', new \DateTimeImmutable('2026-01-01'), 'i', 'c', 'r', 'inv', $first->getTranslationGroup());
        $administrator->create(Locale::EN, 'Second, in English', 'v0.2.0', new \DateTimeImmutable('2026-01-02'), 'i', 'c', 'r', 'inv', $second->getTranslationGroup());

        self::assertSame(0, $first->getPosition());
        self::assertSame(1, $second->getPosition());

        return [$first->getTranslationGroup()->toRfc4122(), $second->getTranslationGroup()->toRfc4122()];
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
