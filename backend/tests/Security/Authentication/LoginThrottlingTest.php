<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Application\CpgUserRegistrarInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le `login_throttling` du firewall "login" (config/packages/security.yaml) :
 * au-delà de 5 échecs pour un même couple (IP, identifiant), les tentatives
 * suivantes sont bloquées — y compris avec le bon mot de passe — sans que la
 * vérification n'ait lieu. Le failure handler Lexik répond alors 401 avec le
 * message « Too many failed login attempts » (et non 200 ni 429).
 * Régression du point d'audit M1 (brute-force non borné sur /api/login_check).
 */
final class LoginThrottlingTest extends WebTestCase
{
    private const string PASSWORD = 'SecurePassword123';

    /**
     * Le compteur de `login_throttling` est indexé par (IP, identifiant) et
     * persiste dans var/cache entre deux exécutions pendant toute la fenêtre
     * (15 min). Un identifiant unique par exécution garantit que le test
     * repart toujours d'un compteur vierge, sans dépendre d'une purge de cache.
     */
    private string $username;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->username = 'throttle-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAttemptsBeyondLimitAreBlockedEvenWithValidCredentials(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($this->username, self::PASSWORD);

        // 5 échecs autorisés : chacun renvoie 401 (identifiants invalides).
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, 'wrong-password');
            self::assertResponseStatusCodeSame(401, sprintf('La tentative n°%d aurait dû répondre 401.', $attempt));
            self::assertStringNotContainsStringIgnoringCase('too many', (string) $client->getResponse()->getContent());
        }

        // 6e tentative : le throttling prend le relais, sans vérification du mot de passe.
        $this->attemptLogin($client, 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsStringIgnoringCase('too many failed login attempts', (string) $client->getResponse()->getContent());

        // Le bon mot de passe est lui aussi refusé tant que la fenêtre n'est pas
        // écoulée : sans throttling, cette requête renverrait 200 + cookie BEARER.
        $this->attemptLogin($client, self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsStringIgnoringCase('too many failed login attempts', (string) $client->getResponse()->getContent());
        self::assertNull($client->getCookieJar()->get('BEARER'));
    }

    private function attemptLogin(KernelBrowser $client, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $this->username,
            'password' => $password,
        ]));
    }
}
