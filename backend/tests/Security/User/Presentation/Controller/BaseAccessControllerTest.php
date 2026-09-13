<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ADR 0003 D6 : POST /api/account/base-access émet un jeton ROLE_USER sans
 * jamais créer de compte — voir GuestUserProvider (chaîné avant cpg_users
 * dans security.yaml, ne répond que si le payload JWT porte le marqueur
 * "guest": true) pour la mécanique de ré-authentification sur les requêtes
 * suivantes.
 */
final class BaseAccessControllerTest extends WebTestCase
{
    /**
     * Le quota du rate limiter "base_access" (config/packages/rate_limiter.yaml)
     * est stocké sur cache.rate_limiter (filesystem, survit au redémarrage du
     * kernel) — même rationale que ContactMessageResourceTest::createClientWithFreshRateLimiter().
     */
    private function createClientWithFreshRateLimiter(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    public function testGrantsARoleUserTokenWithoutPersistingAnyAccount(): void
    {
        $client = $this->createClientWithFreshRateLimiter();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $countBefore = $this->countCpgUsers($connection);

        $client->request('POST', '/api/account/base-access');

        self::assertResponseIsSuccessful();

        $bearerCookie = $client->getCookieJar()->get('BEARER');
        self::assertNotNull($bearerCookie);
        self::assertTrue($bearerCookie->isHttpOnly());

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);
        self::assertFalse($csrfCookie->isHttpOnly());

        $countAfter = $this->countCpgUsers($connection);
        self::assertSame($countBefore, $countAfter, 'Aucun compte ne doit être créé en base (D6).');

        // Le corps annonce l'échéance : le cookie est httpOnly, le frontend ne
        // peut pas lire l'expiration du jeton, et sans elle le badge « Accès
        // de base » resterait affiché après les 15 minutes de D6.
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['ROLE_USER'], $payload['roles']);
        self::assertIsString($payload['expiresAt']);
        $expiresAt = new \DateTimeImmutable($payload['expiresAt']);
        $remaining = $expiresAt->getTimestamp() - time();
        self::assertGreaterThan(14 * 60, $remaining);
        self::assertLessThanOrEqual(15 * 60, $remaining);
        self::assertSame($expiresAt->getTimestamp(), (int) $bearerCookie->getExpiresTime(), 'Le corps et le cookie doivent annoncer la même échéance.');

        // Le cookie authentifie bien la requête suivante (403, pas 401) : la
        // mécanique de ré-authentification via GuestUserProvider fonctionne.
        // /api/me exige ROLE_TRUSTED (ADR 0003 D4) : un jeton ROLE_USER seul
        // doit être reconnu comme authentifié mais insuffisant.
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(403);
    }

    private function countCpgUsers(Connection $connection): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM cpg_user');
        \assert(is_numeric($count));

        return (int) $count;
    }

    public function testATwentyFirstRequestFromTheSameClientWithinTheWindowIsRateLimited(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        // Quota (rate_limiter.yaml, limiteur "base_access") : 20 requêtes/heure
        // par IP ; le client de test partage toujours la même IP (127.0.0.1).
        for ($i = 0; $i < 20; ++$i) {
            $client->request('POST', '/api/account/base-access');
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/account/base-access');

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }
}
