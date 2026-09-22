<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours public de définition de mot de passe (A7 / D6 : le jeton voyage
 * dans le corps, jamais dans un chemin) :
 * - POST /api/account/password-setup/validate {token} ;
 * - POST /api/account/password-setup {token, password}.
 * Aucune authentification, aucun CSRF (endpoint public, exclu du
 * double-submit-cookie comme /api/contact). Rate-limité par IP.
 */
final class AccountPasswordSetupResourceTest extends WebTestCase
{
    use HttpJson;
    use InvitesUsers;

    private const string EMAIL = 'newcomer@example.com';
    private const string DERIVED_USERNAME = 'newcomer';

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

    public function testValidateAnswers204ForAKnownUsableTokenWithoutAuthentication(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }

    /**
     * Valider n'est pas consommer : le jeton reste exploitable après autant de
     * validations qu'on veut (le frontend valide à l'affichage du formulaire).
     */
    public function testValidateDoesNotConsumeTheToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testValidateReturns404ForAnUnknownToken(): void
    {
        $client = $this->freshClient();

        $this->postValidate($client, ['token' => bin2hex(random_bytes(32))]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testValidateReturns410ForAnExpiredToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();
        $this->expireAllTokens();

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(410);
    }

    public function testValidateReturns410ForAnAlreadyUsedToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(410);
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('payloadsWithoutAUsableToken')]
    public function testValidateReturns422WithoutAUsableTokenField(array $payload): void
    {
        $client = $this->freshClient();

        $this->postValidate($client, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSetupSetsThePasswordActivatesTheAccountAndConsumesTheToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());

        // Le compte est désormais utilisable avec l'identifiant dérivé.
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::DERIVED_USERNAME,
            'password' => TestCredentials::variant('new'),
        ]));
        self::assertResponseIsSuccessful();

        // Le jeton est consommé : rejouer le POST échoue en 410.
        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(410);
    }

    public function testSetupReturns404ForAnUnknownToken(): void
    {
        $client = $this->freshClient();

        $this->postSetup($client, ['token' => bin2hex(random_bytes(32)), 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetupReturns410ForAnExpiredToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();
        $this->expireAllTokens();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(410);
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('payloadsWithoutAUsableToken')]
    public function testSetupReturns422WithoutAUsableTokenField(array $payload): void
    {
        $client = $this->freshClient();

        $this->postSetup($client, [...$payload, 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function payloadsWithoutAUsableToken(): iterable
    {
        yield 'token absent' => [[]];
        yield 'token vide' => [['token' => '']];
        // Borne haute (Assert\Length max 255) : un jeton réel fait 64 caractères,
        // rien de légitime n'approche cette taille — inutile de le hacher.
        yield 'token démesuré' => [['token' => str_repeat('a', 256)]];
    }

    public function testSetupReturns422ForATooShortPassword(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);

        // Le refus n'a rien consommé : le jeton reste exploitable.
        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * A7 / D6 : l'ancien contrat (jeton dans le chemin) a disparu, il n'en
     * reste ni lecture ni écriture. 404 = aucune route ; 405 = le chemin
     * existe pour une autre méthode — les deux prouvent qu'il n'est plus servi.
     */
    public function testTheLegacyTokenInPathContractIsGone(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $client->request('GET', '/api/account/password-setup/'.$token);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => TestCredentials::variant('new')]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        // Et l'ancien POST n'a rien activé : le jeton est toujours exploitable.
        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Le listener de quota compare des chemins exacts ; le routeur ne sert pas
     * la variante à barre oblique finale d'une route POST (pas de redirection
     * hors GET/HEAD). Ce test épingle cette cohérence : le jour où le routeur
     * servirait `…/password-setup/`, le quota n'y serait pas appliqué.
     */
    public function testTrailingSlashVariantsAreNotServed(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $client->request('POST', '/api/account/password-setup/', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token, 'password' => TestCredentials::variant('new')]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('POST', '/api/account/password-setup/validate/', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }

    public function testRequestsBeyondTheRateLimitReturn429WithRetryAfter(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        // Limite : 10/heure (cf. rate_limiter.yaml). Les 10 premières passent
        // (404 ici), la 11e est rejetée avant même la validation du jeton.
        for ($i = 0; $i < 10; ++$i) {
            $this->postValidate($client, ['token' => $unknown]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postValidate($client, ['token' => $unknown]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    /**
     * Audit C1 / décision D1 : le quota est consommé dans un listener
     * kernel.request, donc AVANT la désérialisation et la validation du corps.
     * Le 11e POST est rejeté en 429 même avec un corps volontairement invalide
     * (ni jeton ni mot de passe valable) : ni la validation (a fortiori l'appel
     * HIBP) ni la résolution du jeton ne sont atteintes.
     */
    public function testSetupBeyondTheRateLimitReturns429BeforeBodyValidation(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 10; ++$i) {
            $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postSetup($client, ['password' => 'short']);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    /**
     * Un corps invalide (422) consomme le quota comme un autre : sonder
     * l'endpoint avec des corps vides n'est pas gratuit.
     */
    public function testAnInvalidBodyConsumesTheQuotaToo(): void
    {
        $client = $this->freshClient();

        for ($i = 0; $i < 10; ++$i) {
            $this->postValidate($client, []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->postValidate($client, []);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Les deux routes partagent le même compteur par IP : 5 + 5 atteignent la
     * limite de 10, le 11e appel (quelle que soit la route) est rejeté.
     */
    public function testValidateAndSetupShareTheSameRateLimitQuota(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 5; ++$i) {
            $this->postValidate($client, ['token' => $unknown]);
            self::assertResponseStatusCodeSame(404);
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postValidate($client, ['token' => $unknown]);
        self::assertResponseStatusCodeSame(429);

        $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Régression issue #77 : `%2D` = '-'. Le routeur sert `password%2Dsetup`
     * comme `password-setup`, le quota par IP doit s'appliquer à l'identique —
     * sur les deux routes.
     */
    public function testPercentEncodedPathDoesNotBypassTheRateLimiter(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));
        $server = ['CONTENT_TYPE' => 'application/json'];

        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/account/password%2Dsetup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('new')]));
            self::assertResponseStatusCodeSame(404);
        }
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/account/password%2Dsetup/valid%61te', server: $server, content: self::jsonBody(['token' => $unknown]));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('POST', '/api/account/password%2Dsetup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('new')]));
        self::assertResponseStatusCodeSame(429);

        $client->request('POST', '/api/account/password%2Dsetup/valid%61te', server: $server, content: self::jsonBody(['token' => $unknown]));
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @param array<string, string> $payload
     */
    private function postValidate(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($payload));
    }

    /**
     * @param array<string, string> $payload
     */
    private function postSetup(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/api/account/password-setup', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($payload));
    }

    private function freshClient(): KernelBrowser
    {
        $client = self::createClient();
        // Le quota est stocké dans le pool "cache.rate_limiter" (Doctrine DBAL
        // depuis l'ADR 0005, table cache_items de la base de test) et survit au
        // redémarrage de kernel (même IP 127.0.0.1 pour tous les tests
        // fonctionnels) : on repart d'un quota vierge à chaque test.
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function inviteAndCollectToken(): string
    {
        // Invite + exécute le handler + relit le jeton en clair dans l'e-mail
        // (il ne transite plus par le message Messenger, cf. audit C2 / D3).
        return $this->inviteAndCollectSetupToken(self::EMAIL, Locale::FR);
    }

    private function expireAllTokens(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE password_setup_token SET expires_at = '2000-01-01 00:00:00'");
    }
}
