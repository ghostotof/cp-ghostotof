<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Tests\Support\HttpJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Parcours public GET/POST /api/account/password-setup/{token} : aucune
 * authentification, aucun CSRF (endpoint public, exclu du double-submit-cookie
 * comme /api/contact). Rate-limité par IP.
 */
final class AccountPasswordSetupResourceTest extends WebTestCase
{
    use HttpJson;

    private const string EMAIL = 'newcomer@example.com';
    private const string DERIVED_USERNAME = 'newcomer';
    private const string NEW_PASSWORD = 'NotCompromisedPass1';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testGetValidatesAKnownUsableTokenWithoutAuthentication(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken($client);

        $client->request('GET', '/api/account/password-setup/'.$token);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($body['valid']);
    }

    public function testGetReturns404ForAnUnknownToken(): void
    {
        $client = $this->freshClient();

        $client->request('GET', '/api/account/password-setup/'.bin2hex(random_bytes(32)));

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetReturns410ForAnExpiredToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken($client);
        $this->expireAllTokens();

        $client->request('GET', '/api/account/password-setup/'.$token);

        self::assertResponseStatusCodeSame(410);
    }

    public function testPostSetsThePasswordActivatesTheAccountAndConsumesTheToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken($client);

        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => self::NEW_PASSWORD]));
        self::assertResponseStatusCodeSame(204);

        // Le compte est désormais utilisable avec l'identifiant dérivé.
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::DERIVED_USERNAME,
            'password' => self::NEW_PASSWORD,
        ]));
        self::assertResponseIsSuccessful();

        // Le jeton est consommé : rejouer le POST échoue en 410.
        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => self::NEW_PASSWORD]));
        self::assertResponseStatusCodeSame(410);
    }

    public function testPostReturns422ForATooShortPassword(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken($client);

        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => 'short']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRequestsBeyondTheRateLimitReturn429WithRetryAfter(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        // Limite : 10/heure (cf. rate_limiter.yaml). Les 10 premières passent
        // (404 ici), la 11e est rejetée avant même la validation du jeton.
        for ($i = 0; $i < 10; ++$i) {
            $client->request('GET', '/api/account/password-setup/'.$unknown);
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('GET', '/api/account/password-setup/'.$unknown);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    /**
     * Audit C1 / décision D1 : le quota est consommé dans un listener
     * kernel.request, donc AVANT la désérialisation et la validation du corps.
     * Le 11e POST est rejeté en 429 même avec un corps volontairement invalide
     * (mot de passe trop court) et un jeton inconnu : ni la résolution du jeton
     * ni la validation (a fortiori l'appel HIBP) ne sont atteintes.
     */
    public function testPostBeyondTheRateLimitReturns429BeforeBodyValidation(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 10; ++$i) {
            $client->request('POST', '/api/account/password-setup/'.$unknown, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => self::NEW_PASSWORD]));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('POST', '/api/account/password-setup/'.$unknown, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => 'short']));

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    /**
     * GET et POST partagent le même compteur par IP : 5 + 5 atteignent la
     * limite de 10, le 11e appel (quelle que soit la méthode) est rejeté.
     */
    public function testGetAndPostShareTheSameRateLimitQuota(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/api/account/password-setup/'.$unknown);
            self::assertResponseStatusCodeSame(404);
        }
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/account/password-setup/'.$unknown, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => self::NEW_PASSWORD]));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('GET', '/api/account/password-setup/'.$unknown);
        self::assertResponseStatusCodeSame(429);
    }

    private function freshClient(): KernelBrowser
    {
        $client = self::createClient();
        // Le quota est stocké sur le cache filesystem "cache.rate_limiter" et
        // survit au redémarrage de kernel (même IP 127.0.0.1 pour tous les
        // tests fonctionnels) : on repart d'un quota vierge à chaque test.
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function inviteAndCollectToken(KernelBrowser $client): string
    {
        $client->getContainer()->get(CpgUserInviterInterface::class)->invite(self::EMAIL, Locale::FR);
        // Détache les entités persistées par l'invitation : la 1re requête HTTP
        // du test réutilise ce kernel/EM ; sans clear(), un findOneBy() ultérieur
        // rendrait l'instance en cache d'identité (ex. expires_at non rafraîchi
        // après un UPDATE SQL brut dans testGetReturns410ForAnExpiredToken).
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);

        return $message->clearToken;
    }

    private function expireAllTokens(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE password_setup_token SET expires_at = '2000-01-01 00:00:00'");
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
