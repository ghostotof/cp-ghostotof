<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Application\CpgUserRegistrarInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le cycle complet login → /api/me → logout → /api/me, cookie httpOnly
 * compris (cf. config/packages/security.yaml, lexik_jwt_authentication.yaml,
 * App\Security\Authentication\Infrastructure\*).
 */
final class AuthenticationFlowTest extends WebTestCase
{
    private const string USERNAME = 'jane';
    private const string PASSWORD = 'SecurePassword123';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        // Le kernel est déjà démarré par le createClient() du test : WebTestCase
        // interdit d'appeler createClient() une seconde fois dans le même test.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testFullLoginMeLogoutCycle(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::USERNAME, self::PASSWORD);

        // 1. Mauvais mot de passe => 401
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => self::USERNAME,
            'password' => 'wrong-password',
        ]));
        self::assertResponseStatusCodeSame(401);

        // 2. /api/me sans cookie => 401
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);

        // 3. Login valide => 200, cookies BEARER (httpOnly) + XSRF-TOKEN posés, plus de "token" dans le corps
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]));
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('token', $data);
        self::assertSame(self::USERNAME, $data['user']['username']);

        $bearerCookie = $client->getCookieJar()->get('BEARER');
        self::assertNotNull($bearerCookie);
        self::assertTrue($bearerCookie->isHttpOnly());

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);
        self::assertFalse($csrfCookie->isHttpOnly());

        // 4. /api/me avec cookie => 200 + username
        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(self::USERNAME, $me['user']['username']);

        // 5. Logout sans header CSRF => 403
        $client->request('POST', '/api/logout');
        self::assertResponseStatusCodeSame(403);

        // 6. Logout avec header CSRF valide => 204, cookies expirés
        $client->request('POST', '/api/logout', server: ['HTTP_X_XSRF_TOKEN' => $csrfCookie->getValue()]);
        self::assertResponseStatusCodeSame(204);

        // 7. /api/me après logout => 401 (le cookie BEARER a été invalidé côté client par la réponse ci-dessus)
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }
}
