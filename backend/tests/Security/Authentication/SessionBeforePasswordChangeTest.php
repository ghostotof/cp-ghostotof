<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpFoundationCookie;

/**
 * Une session ouverte avant le dernier changement de mot de passe du compte est
 * refusée **par toute l'API** (#650, R1b de la relecture), pas seulement au
 * consentement MCP (R1) : le JWT de session n'est pas révocable individuellement
 * (ADR 0003), mais il ne survit plus au mot de passe qu'il a prouvé.
 *
 * Deux familles de tests. Les jetons **forgés** (signés par la clé du site, `iat`
 * choisi à la seconde) pincent la frontière sans dépendre de l'horloge : le
 * changement est daté en SQL, le cookie d'avant, de la même seconde et d'après
 * s'essaient sur `GET /api/me` (le palier de confiance). Le parcours **réel**
 * ouvre la session, fait changer le mot de passe par un `ROLE_SUPER` et rejoue le
 * cookie. Aucun test ne se reconnecte « tout de suite après » un changement : la
 * même seconde est refusée (limite acceptée, `CpgUser::hasChangedCredentialsSince()`).
 */
final class SessionBeforePasswordChangeTest extends WebTestCase
{
    use HttpJson;

    private const string USERNAME = 'jane';
    private const string ADMIN_USERNAME = 'root';
    private const string ME = '/api/me';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /** Un cookie d'avant le changement : refusé, 401, sans rien dire du compte. */
    public function testASessionOpenedBeforeThePasswordChangeIsRefused(): void
    {
        $changedAt = time() - 100;
        $account = $this->registerTrusted();
        $this->datePasswordChange($account, $changedAt);
        $this->replaceTheBearerWith($account, issuedAt: $changedAt - 60);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * Le `iat` est à la seconde et la colonne à la seconde : la **même** seconde
     * ne dit pas laquelle est venue la première, elle est refusée. Rouge si la
     * comparaison devient stricte (`<`).
     */
    public function testASessionOpenedInTheSameSecondAsTheChangeIsRefused(): void
    {
        $changedAt = time() - 100;
        $account = $this->registerTrusted();
        $this->datePasswordChange($account, $changedAt);
        $this->replaceTheBearerWith($account, issuedAt: $changedAt);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /** Une session ouverte la seconde d'après est celle de la personne qui détient le mot de passe : servie. */
    public function testASessionOpenedAfterThePasswordChangeIsServed(): void
    {
        $changedAt = time() - 100;
        $account = $this->registerTrusted();
        $this->datePasswordChange($account, $changedAt);
        $this->replaceTheBearerWith($account, issuedAt: $changedAt + 1);

        $this->client->request('GET', self::ME);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Un compte dont le mot de passe n'a jamais changé n'est jamais refusé, quel
     * que soit l'âge du jeton : rien à comparer. (Le cas d'un instant inconnu
     * sur ce compte, que le fournisseur de jetons de Lexik ne laisse pas
     * produire — il pose toujours `iat` —, est celui du test unitaire de
     * l'écouteur et de `CpgUserTest`.)
     */
    public function testAnAccountWhosePasswordNeverChangedIsNeverRefused(): void
    {
        $account = $this->registerTrusted();

        $this->replaceTheBearerWith($account, issuedAt: 1);

        $this->client->request('GET', self::ME);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Le parcours réel : la personne est connectée, un `ROLE_SUPER` change son
     * mot de passe (`PUT /api/backoffice/users/{id}/password`), son cookie d'avant
     * n'ouvre plus rien dès la requête suivante.
     */
    public function testAPasswordChangeByAnAdministratorClosesTheSessionHeldBefore(): void
    {
        $account = $this->registerTrusted();
        $this->registerAdministrator();
        $this->signInAs(self::USERNAME, TestCredentials::plainPassword());
        $this->client->request('GET', self::ME);
        self::assertResponseStatusCodeSame(200, 'La session ouverte avant le changement sert, avant le changement.');
        $heldBefore = $this->client->getCookieJar()->all();

        $this->signInAs(self::ADMIN_USERNAME, TestCredentials::superPassword());
        $this->client->request('PUT', \sprintf('/api/backoffice/users/%s/password', $account->getId()->toRfc4122()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ], content: self::jsonBody(['password' => TestCredentials::variant('new')]));
        self::assertResponseStatusCodeSame(204);

        $this->restoreCookies($heldBefore);
        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * L'administrateur qui change **son propre** mot de passe : sa session est
     * coupée à la requête suivante, comme toute autre (la décision de l'auteur
     * de R1b ; le parcours du frontend est une question à part).
     */
    public function testAnAdministratorChangingHisOwnPasswordClosesHisOwnSession(): void
    {
        $admin = $this->registerAdministrator();
        $this->signInAs(self::ADMIN_USERNAME, TestCredentials::superPassword());

        $this->client->request('PUT', \sprintf('/api/backoffice/users/%s/password', $admin->getId()->toRfc4122()), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $this->xsrfToken(),
        ], content: self::jsonBody(['password' => TestCredentials::variant('new')]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * Le cookie refusé est **effacé** par la réponse du refus (les deux cookies
     * d'authentification, avec les attributs de la pose, comme la déconnexion) :
     * le firewall `api` authentifie dès qu'un cookie est présent, **même sur une
     * route publique** — un `BEARER` mort resterait une heure dans le navigateur
     * et ferait répondre 401 à tout `/api`, le contenu public compris. Après le
     * refus, le navigateur n'en envoie plus : la requête suivante est anonyme.
     */
    public function testTheRefusalClearsTheDeadCookiesSoThePublicRoutesStayServed(): void
    {
        $changedAt = time() - 100;
        $account = $this->registerTrusted();
        $this->datePasswordChange($account, $changedAt);
        $this->replaceTheBearerWith($account, issuedAt: $changedAt - 60);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
        $factory = self::getContainer()->get(AuthCookieFactory::class);
        $cleared = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cleared[$cookie->getName()] = $this->attributesOf($cookie);
        }
        self::assertSame(
            [
                AuthCookieFactory::BEARER => $this->attributesOf($factory->expired(AuthCookieFactory::BEARER)),
                AuthCookieFactory::XSRF_TOKEN => $this->attributesOf($factory->expired(AuthCookieFactory::XSRF_TOKEN)),
            ],
            $cleared,
        );
        self::assertNull($this->client->getCookieJar()->get('BEARER'), 'Le navigateur garde le cookie refusé.');

        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
    }

    /**
     * L'accès de base n'a pas de compte (ADR 0003 D6) : aucun mot de passe à
     * avoir changé, aucune date à comparer. Son jeton, qui porte lui aussi un
     * `iat`, n'est jamais refusé ici — un 403 (palier insuffisant pour `/api/me`),
     * pas un 401 (session close).
     */
    public function testTheBaseAccessTokenIsNotConcerned(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        // Un compte réel dont le mot de passe a changé n'y change rien.
        $this->datePasswordChange($this->registerTrusted(), time() - 100);

        $this->client->request('GET', self::ME);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * 401, corps du refus d'authentification de Lexik (`code` + `message`) : le
     * frontend le lit comme toute session expirée.
     */
    private function assertRefusedAsAClosedSession(): void
    {
        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(401, $body['code'] ?? null);
        self::assertSame('Session closed by a password change.', $body['message'] ?? null);
        self::assertStringNotContainsString(self::USERNAME, (string) $response->getContent(), 'Le refus ne nomme pas le compte.');
    }

    /**
     * Les attributs d'un cookie hors l'échéance (calculée à l'instant de sa
     * construction, une seconde peut séparer deux constructions).
     *
     * @return array{string, string|null, string, string|null, bool, bool, string|null, bool}
     */
    private function attributesOf(HttpFoundationCookie $cookie): array
    {
        return [$cookie->getName(), $cookie->getValue(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite(), $cookie->getExpiresTime() < time()];
    }

    private function registerTrusted(): CpgUser
    {
        return $this->registrar()->register(self::USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
    }

    private function registerAdministrator(): CpgUser
    {
        return $this->registrar()->register(self::ADMIN_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
    }

    private function registrar(): CpgUserRegistrarInterface
    {
        return self::getContainer()->get(CpgUserRegistrarInterface::class);
    }

    /** Date le changement de mot de passe en SQL, à la seconde (`TIMESTAMP(0)`) : le test choisit l'instant. */
    private function datePasswordChange(CpgUser $account, int $timestamp): void
    {
        $this->connection()->executeStatement(
            'UPDATE cpg_user SET credentials_changed_at = to_timestamp(:at) WHERE id = :id',
            ['at' => $timestamp, 'id' => $account->getId()->toRfc4122()],
        );
    }

    /**
     * Remplace le cookie de session par un jeton **signé par la clé du site**, dont
     * seul l'`iat` est choisi (le fournisseur de Lexik en pose toujours un). Les
     * attributs du cookie sont ceux que la vraie connexion vient de poser.
     */
    private function replaceTheBearerWith(CpgUser $account, int $issuedAt): void
    {
        $payload = ['username' => $account->getUserIdentifier(), 'roles' => $account->getRoles(), 'exp' => time() + 3600, 'iat' => $issuedAt];
        $jwt = self::getContainer()->get(JWTEncoderInterface::class)->encode($payload);

        $jar = $this->client->getCookieJar();
        $issued = $jar->get('BEARER');
        if (null === $issued) {
            $this->signInAs(self::USERNAME, TestCredentials::plainPassword());
            $issued = $jar->get('BEARER') ?? self::fail('Aucun cookie BEARER après la connexion.');
        }
        $jar->set(new Cookie('BEARER', $jwt, null, $issued->getPath(), $issued->getDomain(), $issued->isSecure(), $issued->isHttpOnly(), false, $issued->getSameSite()));
    }

    private function signInAs(string $username, string $password): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();
    }

    private function xsrfToken(): string
    {
        return $this->client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    /** @param array<array-key, Cookie> $cookies */
    private function restoreCookies(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
