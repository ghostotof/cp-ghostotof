<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\ApiResource;

use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec 0004 B4 : PUT /api/backoffice/watch/products/order. Même grille que
 * BackofficeIncidentOrderResourceTest, avec les deux écarts propres au
 * contexte Watch : le corps porte `ids` et non `groups` — un numéro de version
 * est un fait, pas une traduction, il n'y a donc pas de groupe ici — et l'ordre
 * ne se relit que sur la collection de backoffice, `GET /api/watch` ne servant
 * qu'un agrégat.
 *
 * Le catalogue est peuplé par l'Administrator plutôt que par l'API : la
 * contrainte WatchedProductSlugExists interroge endoflife.date à la création,
 * et aucun test ne doit partir sur le réseau (ADR 0002).
 */
final class BackofficeWatchedProductOrderResourceTest extends WebTestCase
{
    use HttpJson;

    private const string ORDER_PATH = '/api/backoffice/watch/products/order';
    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

    /** UUID syntaxiquement valide mais absent du catalogue. */
    private const string UNKNOWN_KEY = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM watched_product');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testReorderingIsReflectedOnTheBackofficeCollection(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoProducts($client);

        $this->putOrder($client, $csrfToken, ['ids' => [$second, $first]]);

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/backoffice/watch/products');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($collection);

        // La collection est déjà servie triée par position : l'ordre des
        // éléments et leurs positions disent la même chose de deux façons.
        self::assertSame([$second, $first], array_column($collection, 'id'));
        self::assertSame([0, 1], array_column($collection, 'position'));
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoProducts($client);

        $body = $this->putOrder($client, $csrfToken, ['ids' => [$second, $first, self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/errors/unknown-order-entry', $body['type'] ?? null);
    }

    public function testAMissingKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first] = $this->seedTwoProducts($client);

        $body = $this->putOrder($client, $csrfToken, ['ids' => [$first]]);

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

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['ids' => [self::UNKNOWN_KEY]]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testBaseTierTokenIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->putOrder($client, $csrfToken, ['ids' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedAccountWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->putOrder($client, $csrfToken, ['ids' => [self::UNKNOWN_KEY]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleSuperWithoutTheCsrfHeaderIsForbidden(): void
    {
        $client = self::createClient();
        $this->registerSuperAdmin($client);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        [$first, $second] = $this->seedTwoProducts($client);

        $client->request('PUT', self::ORDER_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['ids' => [$second, $first]]));

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

        $this->seedTwoProducts($client);

        $this->putOrder($client, $csrfToken, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'liste vide' => [['ids' => []]];
        yield 'champ absent' => [[]];
        yield 'clé non-UUID' => [['ids' => ['pas-un-uuid']]];
        yield 'doublon' => [['ids' => [self::UNKNOWN_KEY, self::UNKNOWN_KEY]]];
        yield 'clé non textuelle' => [['ids' => [['imbriqué']]]];
    }

    /**
     * @return array{string, string} les ids, dans leur ordre initial
     */
    private function seedTwoProducts(KernelBrowser $client): array
    {
        $administrator = $client->getContainer()->get(WatchedProductAdministratorInterface::class);

        $first = $administrator->create('postgresql', 'PostgreSQL', VersionSource::MANUAL, '17.2', 0);
        $second = $administrator->create('rabbitmq', 'RabbitMQ', VersionSource::MANUAL, '4.0', 1);

        return [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
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
