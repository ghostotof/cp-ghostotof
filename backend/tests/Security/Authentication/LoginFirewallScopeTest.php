<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit 2026-09-16, constat A23 : le `pattern` du firewall « login » était
 * `^/api/login`, sans borne de fin. Ce test fixe ce que le resserrement en
 * `^/api/login_check$` doit préserver et ce qu'il doit exclure.
 *
 * Ce qu'il préserve :
 *  - le login nominal, ses deux cookies compris ;
 *  - le traitement du chemin **encodé** `/api/login%5Fcheck`. Le routeur comme
 *    le firewall décident sur `rawurldecode()` (issue #77) : une borne `$` qui
 *    se laisserait contourner par un `%5F` ferait passer la même requête dans
 *    le firewall JWT, hors `login_throttling`. La preuve en est le corps de la
 *    réponse (« Invalid credentials », signé JsonLoginAuthenticator) et le
 *    partage du compteur anti-brute-force avec la forme non encodée ;
 *  - la garde anti login-CSRF, qui matche elle aussi sur le chemin décodé.
 *
 * Ce qu'il exclut : les voisins par préfixe (`/api/login_checkx`,
 * `/api/loginfoo`). Ils sont refusés par le routeur avant même le firewall
 * (priorité 32 contre 8), ce que ce test constate ; l'appartenance au firewall,
 * elle, est vérifiée au niveau du conteneur par AccessControlAnchoringTest.
 */
final class LoginFirewallScopeTest extends WebTestCase
{
    use HttpJson;

    /** Le même chemin que `json_login.check_path`, avec le `_` encodé. */
    private const string ENCODED_LOGIN_PATH = '/api/login%5Fcheck';

    private string $username;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        // Le compteur de login_throttling est indexé par (IP, identifiant) et
        // survit à l'exécution : un identifiant neuf garantit un compteur vierge.
        $this->username = 'scope-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testNominalLoginStillAnswers200WithBothCookies(): void
    {
        $client = $this->createClientWithAccount();

        $this->attemptLogin($client, '/api/login_check', TestCredentials::plainPassword());

        self::assertResponseIsSuccessful();
        self::assertNotNull($client->getCookieJar()->get(AuthCookieFactory::BEARER));
        self::assertNotNull($client->getCookieJar()->get(AuthCookieFactory::XSRF_TOKEN));
    }

    public function testPercentEncodedLoginPathIsStillHandledByTheLoginFirewall(): void
    {
        $client = $this->createClientWithAccount();

        $this->attemptLogin($client, self::ENCODED_LOGIN_PATH, 'wrong-password');

        // 401 « Invalid credentials » : c'est le failure handler Lexik du
        // firewall « login », pas le point d'entrée JWT du firewall « api ».
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Invalid credentials', (string) $client->getResponse()->getContent());

        // Et le login nominal passe par ce même chemin encodé.
        $this->attemptLogin($client, self::ENCODED_LOGIN_PATH, TestCredentials::plainPassword());
        self::assertResponseIsSuccessful();
        self::assertNotNull($client->getCookieJar()->get(AuthCookieFactory::BEARER));
    }

    public function testPercentEncodedLoginPathIsStillCoveredByTheLoginCsrfGuard(): void
    {
        $client = $this->createClientWithAccount();

        // Sans X-Requested-With : LoginCsrfRequestListener (priorité 20, au-dessus
        // du firewall) répond 403 avant toute vérification d'identifiants.
        $client->request('POST', self::ENCODED_LOGIN_PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => $this->username,
            'password' => TestCredentials::plainPassword(),
        ]));

        self::assertResponseStatusCodeSame(403);
        self::assertNull($client->getCookieJar()->get(AuthCookieFactory::BEARER));
    }

    public function testPercentEncodedLoginPathSharesTheThrottlingCounter(): void
    {
        $client = $this->createClientWithAccount();

        // Cinq échecs par le chemin encodé : s'ils échappaient au firewall
        // « login », ils ne compteraient pas et la 6e tentative passerait.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, self::ENCODED_LOGIN_PATH, 'wrong-password');
            self::assertResponseStatusCodeSame(401, sprintf('La tentative n°%d aurait dû répondre 401.', $attempt));
        }

        // Sixième tentative par le chemin non encodé, avec le **bon** mot de
        // passe : le throttling doit la refuser, donc les cinq précédentes ont
        // bien été comptées sur la même clé.
        $this->attemptLogin($client, '/api/login_check', TestCredentials::plainPassword());

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsStringIgnoringCase('too many failed login attempts', (string) $client->getResponse()->getContent());
        self::assertNull($client->getCookieJar()->get(AuthCookieFactory::BEARER));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideNeighbouringPaths(): iterable
    {
        yield '/api/login_checkx' => ['/api/login_checkx'];
        yield '/api/loginfoo' => ['/api/loginfoo'];
        yield '/api/login' => ['/api/login'];
        yield '/api/login_check/extra' => ['/api/login_check/extra'];
    }

    #[DataProvider('provideNeighbouringPaths')]
    public function testNeighbouringPathsNeverLogAnybodyIn(string $path): void
    {
        $client = $this->createClientWithAccount();

        $this->attemptLogin($client, $path, TestCredentials::plainPassword());

        // Aucune route n'existe sous ces chemins : le routeur (priorité 32)
        // répond 404 avant que le firewall (priorité 8) n'ait son mot à dire.
        // L'assertion de fond est la suivante : jamais 200, jamais de BEARER.
        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [401, 403, 404],
            sprintf('%s devrait être refusé (401/403/404).', $path),
        );
        self::assertNull($client->getCookieJar()->get(AuthCookieFactory::BEARER), sprintf('%s a posé un cookie BEARER.', $path));
    }

    private function createClientWithAccount(): KernelBrowser
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($this->username, TestCredentials::plainPassword());

        return $client;
    }

    private function attemptLogin(KernelBrowser $client, string $path, string $password): void
    {
        $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $this->username,
            'password' => $password,
        ]));
    }
}
