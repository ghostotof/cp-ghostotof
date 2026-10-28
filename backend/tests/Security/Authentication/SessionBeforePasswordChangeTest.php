<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpFoundationCookie;
use Symfony\Component\Uid\Uuid;

/**
 * Une session ouverte avant le dernier changement de mot de passe du compte est
 * refusée **par toute l'API** (#650, R1b de la relecture), pas seulement au
 * consentement MCP (R1) : le JWT de session n'est pas révocable individuellement
 * (ADR 0003), mais il ne survit plus au mot de passe qu'il a prouvé.
 *
 * Depuis S1 (seconde relecture, F-A et F-B), ce n'est plus une date comparée à
 * l'instant de signature du jeton : le jeton **porte la version des
 * identifiants** que le compte avait quand son mot de passe a été vérifié
 * (revendication `cv`, posée à la création), et il est refusé dès qu'elle
 * diffère de la version courante. Aucune horloge ne compte, donc aucune
 * seconde « douteuse » : se reconnecter tout de suite après un changement est
 * servi, et une connexion qui a lu l'ancien mot de passe avant le changement ne
 * passe jamais, quel que soit l'instant où son jeton est signé.
 *
 * Deux familles de tests. Les jetons **forgés** (signés par la clé du site, `cv`
 * et `iat` choisis) pincent la règle ; le parcours **réel** ouvre la session,
 * fait changer le mot de passe par un `ROLE_SUPER` et rejoue le cookie. Le test
 * de la course crée le jeton depuis l'instance lue **avant** le changement.
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

    /** Un cookie d'avant le changement (version précédente) : refusé, 401, sans rien dire du compte. */
    public function testASessionOpenedBeforeThePasswordChangeIsRefused(): void
    {
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 3);
        $this->replaceTheBearerWith($account, version: 2);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /** La version courante est celle de la personne qui détient le mot de passe : servie. */
    public function testASessionCarryingTheCurrentVersionIsServed(): void
    {
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 3);
        $this->replaceTheBearerWith($account, version: 3);

        $this->client->request('GET', self::ME);

        self::assertResponseStatusCodeSame(200);
    }

    /** Une version que le compte n'a pas (encore) : refusée aussi, c'est une égalité. */
    public function testASessionCarryingAnotherVersionIsRefusedWhicheverSide(): void
    {
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 3);
        $this->replaceTheBearerWith($account, version: 4);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * L'instant de signature ne compte plus (F-A, F-B) : un `iat` ancien ou
     * d'à l'instant ne change ni le refus ni le service. Le verdict est celui de
     * la version. (Un `iat` dans le futur, l'horloge d'un pod en avance, est
     * refusé par Lexik lui-même avant notre règle : hors du sujet.)
     */
    #[DataProvider('issuedAtInstants')]
    public function testTheIssuedAtPlaysNoPartInTheVerdict(int $issuedAt): void
    {
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 1);

        $this->replaceTheBearerWith($account, version: 1, issuedAt: $issuedAt);
        $this->client->request('GET', self::ME);
        self::assertResponseStatusCodeSame(200);

        $this->replaceTheBearerWith($account, version: 0, issuedAt: $issuedAt);
        $this->client->request('GET', self::ME);
        $this->assertRefusedAsAClosedSession();
    }

    /** @return iterable<string, array{int}> */
    public static function issuedAtInstants(): iterable
    {
        yield 'depuis longtemps' => [1];
        yield 'à l\'instant' => [time()];
    }

    /**
     * Un compte jamais changé (version 0) sert un jeton sans revendication : celui
     * d'une session ouverte avant le déploiement n'en a pas, et elle ne saute pas.
     */
    public function testATokenWithoutClaimIsServedWhileThePasswordNeverChanged(): void
    {
        $account = $this->registerTrusted();

        $this->replaceTheBearerWith($account, version: null);

        $this->client->request('GET', self::ME);

        self::assertResponseStatusCodeSame(200);
    }

    /** …et refusé dès que le mot de passe a changé : l'absence de revendication vaut la version 0. */
    public function testATokenWithoutClaimIsRefusedOnceThePasswordChanged(): void
    {
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 1);

        $this->replaceTheBearerWith($account, version: null);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * **La course de F-A.** Une connexion avec l'ancien mot de passe lit le
     * compte (sans verrou), vérifie le hachage — de l'ordre de 270 ms —, et c'est
     * après seulement que Lexik crée le jeton. Entre-temps le changement de mot de
     * passe a commité. Avec la date, la connexion qui signait une seconde après
     * le changement passait ; avec la version, le jeton porte celle de
     * l'instance lue avant le changement, donc l'ancienne : refusé à la requête
     * suivante, sans rien devoir à l'horloge.
     */
    public function testALoginThatReadTheOldHashBeforeTheChangeIsRefusedWhateverWhenItIsSigned(): void
    {
        $this->registerTrusted();
        $accountId = $this->accountId();
        // Les attributs du cookie, ceux de la vraie connexion, avant que l'ancien mot de passe ne meure.
        $this->signInAs(self::USERNAME, TestCredentials::plainPassword());
        $repository = self::getContainer()->get(CpgUserRepositoryInterface::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // La connexion lit le compte et vérifie l'ancien hachage…
        $readByTheLogin = $repository->findOneById($accountId) ?? self::fail('Compte introuvable.');
        $entityManager->detach($readByTheLogin);
        self::assertSame(0, $readByTheLogin->getCredentialsVersion());

        // … le changement de mot de passe commite entre-temps (le service réel)…
        self::getContainer()->get(CpgUserAdministratorInterface::class)->changePassword($accountId, TestCredentials::variant('new'));

        // … puis Lexik crée le jeton, bien après : `iat` est postérieur au changement, de plus d'une seconde.
        sleep(2);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($readByTheLogin);
        $this->putTheBearer($jwt);

        $this->client->request('GET', self::ME);

        $this->assertRefusedAsAClosedSession();
    }

    /**
     * Le pendant, sans course : la connexion qui lit le compte **après** le
     * changement porte la version courante et est servie (la limite acceptée de
     * R1b — la même seconde refusée — a disparu).
     */
    public function testALoginReadingTheAccountAfterTheChangeIsServed(): void
    {
        $this->registerTrusted();
        $accountId = $this->accountId();
        self::getContainer()->get(CpgUserAdministratorInterface::class)->changePassword($accountId, TestCredentials::variant('new'));

        $this->signInAs(self::USERNAME, TestCredentials::variant('new'));
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
        $account = $this->registerTrusted();
        $this->setCredentialsVersion($account, 1);
        $this->replaceTheBearerWith($account, version: 0);

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
     * avoir changé, aucune version à comparer. Son jeton, qui porte lui aussi un
     * `iat`, n'est jamais refusé ici — un 403 (palier insuffisant pour `/api/me`),
     * pas un 401 (session close).
     */
    public function testTheBaseAccessTokenIsNotConcerned(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        // Un compte réel dont le mot de passe a changé n'y change rien.
        $this->setCredentialsVersion($this->registerTrusted(), 1);

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

    /** Pose la version des identifiants du compte en SQL : le test choisit celle que la base a. */
    private function setCredentialsVersion(CpgUser $account, int $version): void
    {
        $this->connection()->executeStatement(
            'UPDATE cpg_user SET credentials_version = :version WHERE id = :id',
            ['version' => $version, 'id' => $account->getId()->toRfc4122()],
        );
    }

    private function accountId(): Uuid
    {
        $id = $this->connection()->fetchOne('SELECT id FROM cpg_user WHERE username = :u', ['u' => self::USERNAME]);
        self::assertIsString($id);

        return Uuid::fromString($id);
    }

    /**
     * Remplace le cookie de session par un jeton **signé par la clé du site**, dont
     * seuls la version (`null` : pas de revendication, un jeton d'avant le
     * déploiement) et l'`iat` sont choisis. Les attributs du cookie sont ceux que
     * la vraie connexion vient de poser.
     */
    private function replaceTheBearerWith(CpgUser $account, ?int $version, ?int $issuedAt = null): void
    {
        $payload = ['username' => $account->getUserIdentifier(), 'roles' => $account->getRoles(), 'exp' => time() + 7200, 'iat' => $issuedAt ?? time()];
        if (null !== $version) {
            $payload[CredentialsVersionClaim::NAME] = $version;
        }

        $this->putTheBearer(self::getContainer()->get(JWTEncoderInterface::class)->encode($payload));
    }

    /** Dépose ce JWT dans le cookie `BEARER`, avec les attributs de la vraie connexion. */
    private function putTheBearer(string $jwt): void
    {
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
