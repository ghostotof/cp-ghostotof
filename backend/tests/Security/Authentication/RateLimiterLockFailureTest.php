<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\UnavailableLockStore;
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
 * La panne est simulée en remplaçant le store de la fabrique de verrous
 * partagée par tous les limiteurs (`lock.default.factory`) avant la première
 * requête du client : même kernel, même instance.
 */
final class RateLimiterLockFailureTest extends WebTestCase
{
    use HttpJson;
    use ReadsSecurityAuditLog;

    private function createClientWithUnavailableLock(): KernelBrowser
    {
        $client = self::createClient();
        // Une requête du client recrée le kernel à partir de la deuxième :
        // on n'en fait qu'une par test, la substitution tient donc.
        // phpstan-symfony connaît le type (LockFactory) depuis le dump du conteneur.
        $lockFactory = self::getContainer()->get('lock.default.factory');
        (new \ReflectionProperty(LockFactory::class, 'store'))->setValue($lockFactory, new UnavailableLockStore());

        return $client;
    }

    public function testALockFailureOnLoginAnswers503ProblemWithRetryAfter(): void
    {
        $client = $this->createClientWithUnavailableLock();

        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => 'lock-failure-probe',
            'password' => 'not-checked',
        ]));

        $this->assertRateLimiterUnavailableProblem($client);
        self::assertCount(1, self::securityAuditEvents('rate-limiter-unavailable'));
        // Aucun identifiant n'a été vérifié : ce n'est pas un échec de login.
        self::assertSame([], self::securityAuditEvents('login-failed'));
    }

    public function testALockFailureOnAnApiPlatformRouteAnswers503ProblemWithRetryAfter(): void
    {
        $client = $this->createClientWithUnavailableLock();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]));

        $this->assertRateLimiterUnavailableProblem($client);
        $events = self::securityAuditEvents('rate-limiter-unavailable');
        self::assertCount(1, $events);
        self::assertSame('/api/contact', $events[0]['path']);
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
        self::assertStringNotContainsString('lock-failure-probe', $content);
        self::assertStringNotContainsStringIgnoringCase('Failed to acquire', $content);
    }
}
