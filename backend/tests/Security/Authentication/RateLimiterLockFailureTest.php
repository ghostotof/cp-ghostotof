<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use App\Tests\Support\UnavailableLockStore;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;

/**
 * Issue #276 : quand le verrou partagé des limiteurs tombe (ADR 0005 D8/D10),
 * la requête est refusée — jamais laissée passer — mais elle répondait une 500
 * générique, sans Retry-After, et le login ne laissait aucune trace au journal
 * d'audit. Attendu : 503 problem+json `/errors/rate-limiter-unavailable` avec
 * Retry-After, et un événement `rate-limiter-unavailable`.
 *
 * Une route par lieu de consommation d'un limiteur : `LoginThrottlingListener`
 * (login), listener `kernel.request` avant le firewall (base-access,
 * password-setup), processor API Platform anonyme (contact) et authentifié
 * (traductions).
 *
 * La panne est simulée en remplaçant le store de la fabrique de verrous
 * partagée par tous les limiteurs (`lock.default.factory`) : même kernel, même
 * instance, tant que le client ne redémarre pas son kernel.
 */
final class RateLimiterLockFailureTest extends WebTestCase
{
    use HttpJson;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;

    private const string SUPER_USERNAME = 'lock-failure-super';

    /** Identifiant tenté au login : il entre dans le nom du verrou de login_throttling. */
    private const string PROBE_USERNAME = 'lock-failure-probe';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $body
     */
    #[DataProvider('anonymousRateLimitedRoutes')]
    public function testALockFailureOnAnAnonymousRateLimitedRouteAnswers503(string $path, array $server, array $body): void
    {
        $client = self::createClient();
        $this->makeTheLockUnavailable();

        $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', ...$server], content: self::jsonBody($body));

        $this->assertRateLimiterUnavailableProblem($client);
        $events = self::securityAuditEvents('rate-limiter-unavailable');
        self::assertCount(1, $events);
        self::assertSame($path, $events[0]['path']);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, array<string, string>}>
     */
    public static function anonymousRateLimitedRoutes(): iterable
    {
        yield 'login (login_throttling)' => ['/api/login_check', ['HTTP_X_REQUESTED_WITH' => 'fetch'], ['username' => self::PROBE_USERNAME, 'password' => 'not-checked']];
        yield 'contact (processor API Platform)' => ['/api/contact', [], ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'message' => 'Bonjour, je souhaite vous contacter pour un projet.']];
        yield 'accès de base (listener kernel.request)' => ['/api/account/base-access', ['HTTP_X_REQUESTED_WITH' => 'fetch'], []];
        yield 'validation du lien (listener kernel.request)' => ['/api/account/password-setup/validate', [], ['token' => 'not-checked']];
        yield 'définition du mot de passe (listener kernel.request)' => ['/api/account/password-setup', [], ['token' => 'not-checked', 'password' => 'not-checked-either']];
    }

    public function testALockFailureOnLoginIsNotALoginFailure(): void
    {
        $client = self::createClient();
        $this->makeTheLockUnavailable();

        $this->attemptLogin($client);

        self::assertResponseStatusCodeSame(503);
        // Aucun identifiant n'a été vérifié : ce n'est pas un échec de login.
        self::assertSame([], self::securityAuditEvents('login-failed'));
    }

    /**
     * Route authentifiée, limiteur indexé sur le compte : la connexion se fait
     * avec le vrai verrou, la panne n'intervient qu'ensuite.
     */
    public function testALockFailureOnTheAuthenticatedTranslationRouteAnswers503(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::SUPER_USERNAME,
            'password' => TestCredentials::superPassword(),
        ]));
        self::assertResponseIsSuccessful();
        $csrfToken = $client->getCookieJar()->get('XSRF-TOKEN')?->getValue();
        self::assertNotNull($csrfToken);

        $this->makeTheLockUnavailable();
        $client->request('POST', '/api/backoffice/translations', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken], content: self::jsonBody([
            'sourceLocale' => 'fr',
            'targetLocale' => 'en',
            'fields' => ['title' => 'Panne du verrou'],
        ]));

        try {
            $this->assertRateLimiterUnavailableProblem($client);
            $event = self::securityAuditEvents('rate-limiter-unavailable')[0] ?? null;
            self::assertNotNull($event);
            self::assertSame(self::SUPER_USERNAME, $event['actor']);
        } finally {
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        }
    }

    /**
     * Le nom du verrou contient la clé du limiteur — ici l'identifiant tenté
     * (et l'IP). Le noyau journalisait le message de l'exception en
     * `critical` sur le canal principal ; il ne doit plus sortir que par le
     * canal `lock`, que la sonde de test capte mais que la production ne
     * journalise pas (handler à `warning`, issue #315, LockLogChannelTest).
     * L'incident, lui, reste visible : une ligne `error` sans ce nom.
     */
    public function testTheLockResourceNeverReachesALogOutsideTheLockChannel(): void
    {
        $client = self::createClient();
        $this->makeTheLockUnavailable();

        $this->attemptLogin($client);

        self::assertResponseStatusCodeSame(503);
        $incidentLogged = false;

        foreach (self::allChannelsLogRecords() as $record) {
            if ('lock' === $record->channel) {
                continue;
            }

            $serialized = json_encode([$record->message, $record->context, $record->extra], \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::PROBE_USERNAME, $serialized, \sprintf('Canal %s : %s', $record->channel, $record->message));
            self::assertStringNotContainsStringIgnoringCase('Failed to acquire', $serialized, \sprintf('Canal %s : %s', $record->channel, $record->message));

            $incidentLogged = $incidentLogged || ($record->level->isHigherThan(Level::Warning) && 'security_audit' !== $record->channel);
        }

        self::assertTrue($incidentLogged, 'La panne du verrou doit rester visible côté exploitation (niveau error ou plus).');
    }

    private function makeTheLockUnavailable(): void
    {
        // phpstan-symfony connaît le type (LockFactory) depuis le dump du conteneur.
        $lockFactory = self::getContainer()->get('lock.default.factory');
        (new \ReflectionProperty(LockFactory::class, 'store'))->setValue($lockFactory, new UnavailableLockStore());
    }

    private function attemptLogin(KernelBrowser $client): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::PROBE_USERNAME,
            'password' => 'not-checked',
        ]));
    }

    private function assertRateLimiterUnavailableProblem(KernelBrowser $client): void
    {
        $response = $client->getResponse();
        $content = (string) $response->getContent();

        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $response->headers->get('Retry-After'));

        $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('/errors/rate-limiter-unavailable', $body['type']);
        self::assertSame(503, $body['status']);

        // Le message de LockAcquiringException nomme la ressource verrouillée,
        // qui contient la clé du limiteur (une IP, un identifiant) : il ne
        // doit jamais sortir dans la réponse.
        self::assertStringNotContainsString('127.0.0.1', $content);
        self::assertStringNotContainsString(self::PROBE_USERNAME, $content);
        self::assertStringNotContainsStringIgnoringCase('Failed to acquire', $content);
    }
}
