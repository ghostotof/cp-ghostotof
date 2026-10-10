<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\Authentication\Domain\Exception\LoginRateLimitExceededException;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Couvre le `login_throttling` du firewall "login" (config/packages/security.yaml) :
 * au-delà de 5 échecs pour un même couple (IP, identifiant), les tentatives
 * suivantes sont bloquées — y compris avec le bon mot de passe — sans que la
 * vérification n'ait lieu.
 * Régression du point d'audit M1 (brute-force non borné sur /api/login_check).
 *
 * Depuis l'issue #399, le refus est un 429 `/errors/rate-limited` avec
 * `Retry-After`, comme tout autre refus de débit (issue #369) : il répondait
 * auparavant le 401 de Lexik, « Too many failed login attempts », qu'un client
 * ne distinguait d'un mot de passe faux qu'en comparant une chaîne.
 */
final class LoginThrottlingTest extends WebTestCase
{
    use HttpJson;
    use ReadsAllChannelsLog;

    /** La fenêtre de `login_throttling` (security.yaml) : l'échéance ne peut pas la dépasser. */
    private const int THROTTLING_INTERVAL_SECONDS = 15 * 60;

    /**
     * Les six tentatives tiennent en quelques secondes, la fenêtre fixe vient
     * donc de s'ouvrir : l'échéance réelle est à une minute près de sa fin.
     * Une borne basse à 14 minutes distingue ce vrai chemin du repli à une
     * minute de LoginThrottlingRefusalListener, que `> 0` laissait passer —
     * si Symfony cessait de fournir `%minutes%`, le test le verrait.
     */
    private const int MINIMAL_RETRY_AFTER_SECONDS = 14 * 60;

    /**
     * Le compteur de `login_throttling` est indexé par (IP, identifiant) et
     * persiste dans la base de test (pool `cache.rate_limiter`, table
     * `cache_items` depuis l'ADR 0005) pendant toute la fenêtre (15 min). Un
     * identifiant unique par exécution fait repartir le compteur local de zéro.
     */
    private string $username;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->username = 'throttle-'.bin2hex(random_bytes(6));
    }

    /**
     * Le pool est vidé à la fin de chaque test, pas seulement le compte : le
     * compteur **global** par IP (25 échecs, `DefaultLoginRateLimiter`) est
     * commun à tous les tests fonctionnels (127.0.0.1). Les six ou sept échecs
     * que chaque test de cette classe y laisse feraient sinon refuser, plus
     * loin dans la suite, le login légitime d'un autre test.
     */
    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        self::getContainer()->get('cache.rate_limiter')->clear();
        parent::tearDown();
    }

    public function testAttemptsBeyondLimitAreBlockedEvenWithValidCredentials(): void
    {
        $client = $this->clientWithAccount();
        $this->exhaustTheAllowedFailures($client);

        // 6e tentative : le throttling prend le relais, sans vérification du mot de passe.
        $this->attemptLogin($client, 'wrong-password');
        $this->assertRateLimitedProblem($client->getResponse());

        // Le bon mot de passe est lui aussi refusé tant que la fenêtre n'est pas
        // écoulée : sans throttling, cette requête renverrait 200 + cookie BEARER.
        $this->attemptLogin($client, TestCredentials::plainPassword());
        $this->assertRateLimitedProblem($client->getResponse());
        self::assertNull($client->getCookieJar()->get('BEARER'));
    }

    /**
     * Les cinq échecs ordinaires gardent le 401 de Lexik, à l'octet près :
     * c'est la moitié « contenu » de la non-énumération (audit A10), que seul
     * le refus de débit, qui ne dit rien du compte, a le droit de quitter.
     */
    public function testTheFailuresBeforeTheLimitKeepLexiksUnchanged401(): void
    {
        $client = $this->clientWithAccount();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, 'wrong-password');
            $response = $client->getResponse();
            self::assertSame(401, $response->getStatusCode(), \sprintf('La tentative n°%d aurait dû répondre 401.', $attempt));
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            self::assertSame('{"code":401,"message":"Invalid credentials."}', $response->getContent());
            self::assertFalse($response->headers->has('Retry-After'));
        }
    }

    /**
     * Le refus est journalisé une fois par le noyau, en `info` : sans entrée
     * dans `framework.exceptions`, une exception qui n'est pas une
     * HttpException part en `critical`, une alerte de production qu'un anonyme
     * déclencherait à volonté (issue #348).
     */
    public function testTheRefusalIsLoggedOnceAsAnInfo(): void
    {
        $client = $this->clientWithAccount();
        $this->exhaustTheAllowedFailures($client);

        $this->attemptLogin($client, 'wrong-password');

        $kernelRecords = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => ($record->context['exception'] ?? null) instanceof LoginRateLimitExceededException,
        ));
        self::assertCount(1, $kernelRecords, 'Le noyau journalise l\'exception une fois.');
        self::assertSame(Level::Info, $kernelRecords[0]->level);
    }

    private function assertRateLimitedProblem(Response $response): void
    {
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $retryAfter = (int) $response->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(self::MINIMAL_RETRY_AFTER_SECONDS, $retryAfter, 'Retry-After ne suit plus l\'échéance du limiteur : le repli à une minute a pris le relais.');
        self::assertLessThanOrEqual(self::THROTTLING_INTERVAL_SECONDS, $retryAfter);

        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('/errors/rate-limited', $problem['type'] ?? null);
        self::assertSame(429, $problem['status'] ?? null);
    }

    private function clientWithAccount(): KernelBrowser
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($this->username, TestCredentials::plainPassword());

        return $client;
    }

    /** Les 5 échecs autorisés, chacun en 401 (identifiants invalides). */
    private function exhaustTheAllowedFailures(KernelBrowser $client): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attemptLogin($client, 'wrong-password');
            self::assertResponseStatusCodeSame(401, \sprintf('La tentative n°%d aurait dû répondre 401.', $attempt));
        }
    }

    private function attemptLogin(KernelBrowser $client, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $this->username,
            'password' => $password,
        ]));
    }
}
