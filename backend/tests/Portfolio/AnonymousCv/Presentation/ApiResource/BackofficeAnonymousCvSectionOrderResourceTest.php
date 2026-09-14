<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Presentation\ApiResource;

use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
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
 * Spec 0004 B4 : PUT /api/backoffice/anonymous-cv/order. Même grille que
 * BackofficeIncidentOrderResourceTest, qui porte le commentaire de fond :
 * le nouvel ordre se relit sur la collection de backoffice **et** sur les
 * endpoints publics des deux langues — un ordre qui divergerait entre le FR et
 * l'EN contredirait D5.
 *
 * `GET /api/anonymous-cv/{locale}` exige ROLE_USER (ADR 0003 D5) : la session
 * ROLE_SUPER du test le couvre par le role_hierarchy.
 */
final class BackofficeAnonymousCvSectionOrderResourceTest extends WebTestCase
{
    use HttpJson;

    private const string ORDER_PATH = '/api/backoffice/anonymous-cv/order';
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
        $connection->executeStatement('DELETE FROM anonymous_cv_section');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testReorderingIsReflectedOnTheBackofficeCollectionAndBothPublicEndpoints(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedEntries($client);

        $this->putOrder($client, $csrfToken, ['groups' => [$second, $first]]);

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/backoffice/anonymous-cv?locale=fr');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($collection);
        // La collection ?locale=fr est servie triée par position : la séquence
        // des groupes et leurs positions disent la même chose de deux façons.
        self::assertSame([$second, $first], array_column($collection, 'translationGroup'));
        self::assertSame([0, 1], array_column($collection, 'position'));

        $client->request('GET', '/api/anonymous-cv/fr');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second', 'Premier'], array_column($payload, 'title'));

        $client->request('GET', '/api/anonymous-cv/en');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Second, in English', 'First'], array_column($payload, 'title'));
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoTranslatedEntries($client);

        $body = $this->putOrder($client, $csrfToken, ['groups' => [$second, $first, self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-order-entry', $body['type'] ?? null);
    }

    public function testAMissingKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first] = $this->seedTwoTranslatedEntries($client);

        $body = $this->putOrder($client, $csrfToken, ['groups' => [$first]]);

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

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['groups' => [self::UNKNOWN_KEY]]));

        self::assertResponseStatusCodeSame(403);
    }

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

        [$first, $second] = $this->seedTwoTranslatedEntries($client);

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['groups' => [$second, $first]]));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Les refus que la validation du DTO tranche avant le domaine. Le dernier
     * — une valeur qui n'est même pas une chaîne — mérite son cas : `Assert\Uuid`
     * lève une UnexpectedValueException (500) sur un tableau, ce que
     * `Assert\Sequentially` évite en s'arrêtant au `Type`.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreRejectedByValidation(array $payload): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $this->seedTwoTranslatedEntries($client);

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
     * Deux sections de CV sans identité, chacune en FR et en EN.
     *
     * @return array{string, string} les clés d'ordre, dans leur ordre initial
     */
    private function seedTwoTranslatedEntries(KernelBrowser $client): array
    {
        $administrator = $client->getContainer()->get(AnonymousCvSectionAdministratorInterface::class);

        $first = $administrator->create(Locale::FR, 'Premier', 'PHP, Symfony', 10, 'réalisations');
        $second = $administrator->create(Locale::FR, 'Second', 'Docker', 5, 'réalisations');
        $administrator->create(Locale::EN, 'First', 'PHP, Symfony', 10, 'achievements', $first->getTranslationGroup());
        $administrator->create(Locale::EN, 'Second, in English', 'Docker', 5, 'achievements', $second->getTranslationGroup());

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
