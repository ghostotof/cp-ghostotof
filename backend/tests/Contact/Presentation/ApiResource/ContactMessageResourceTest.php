<?php

declare(strict_types=1);

namespace App\Tests\Contact\Presentation\ApiResource;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Tests\Support\HttpJson;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Test fonctionnel de bout en bout : requête HTTP -> validation -> dispatch
 * Messenger. Pas de vrai broker (cf. when@test dans messenger.yaml, transport
 * "async" en in-memory), ce qui permet d'inspecter les messages envoyés sans
 * dépendre de RabbitMQ ni consommer réellement le message (donc sans envoyer
 * de vrai email).
 */
final class ContactMessageResourceTest extends WebTestCase
{
    use HttpJson;

    /**
     * Le quota du rate limiter "contact_form" (config/packages/rate_limiter.yaml)
     * est stocké sur le pool de cache "cache.rate_limiter", backé par le
     * filesystem (comme en prod/dev, cf. config/packages/cache.yaml) : il
     * survit donc à un redémarrage de kernel, contrairement au transport
     * Messenger "async" déjà réinitialisé par when@test. Sans ce clear(), les
     * tests dépendraient de leur ordre d'exécution et de l'historique des
     * exécutions précédentes (même IP client 127.0.0.1 partagée par tous les
     * tests fonctionnels de ce fichier). getContainer() ne peut être appelé
     * qu'après createClient() (WebTestCase l'interdit avant), d'où ce point
     * d'entrée unique à la place d'un simple self::createClient().
     */
    private function createClientWithFreshRateLimiter(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    public function testAValidSubmissionIsAcceptedAndDispatchedForAsyncDelivery(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]));

        self::assertResponseStatusCodeSame(202);

        $envelopes = $this->asyncTransport()->getSent();
        self::assertCount(1, $envelopes);

        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(SendContactMessageMessage::class, $message);
        self::assertSame('Jane Doe', $message->senderName);
        self::assertSame('jane@example.com', $message->senderEmail);
        self::assertSame('Bonjour, je souhaite vous contacter pour un projet.', $message->body);
    }

    public function testAnInvalidEmailIsRejectedWithoutDispatchingAnything(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testABlankMessageIsRejectedWithoutDispatchingAnything(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testAWhitespaceOnlyMessageIsRejectedWithoutDispatchingAnything(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            // Uniquement des espaces : sans normalizer 'trim' sur les
            // contraintes NotBlank/Length, ce message passait la validation
            // puis partait vide après le trim() du Processor.
            'message' => '              ',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testAFilledHoneypotIsSilentlyAcceptedWithoutDispatchingAnything(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'Ceci est un message envoyé par un robot de spam.',
            'website' => 'http://spam.example',
        ]));

        // Réponse identique à une soumission légitime : ne jamais révéler au
        // bot que le honeypot a été détecté.
        self::assertResponseStatusCodeSame(202);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testASixthSubmissionFromTheSameClientWithinTheWindowIsRateLimited(): void
    {
        $client = $this->createClientWithFreshRateLimiter();
        $payload = self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]);

        // Le quota (config/packages/rate_limiter.yaml, limiteur "contact_form")
        // est de 5 requêtes/heure par IP ; le client de test partage toujours la
        // même IP (127.0.0.1) d'une requête à l'autre.
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
            self::assertResponseStatusCodeSame(202);
        }

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseStatusCodeSame(429);
        // Le transport in-memory est réinitialisé à chaque requête simulée par
        // le client de test (cf. testAValidSubmissionIsAcceptedAndDispatchedForAsyncDelivery
        // pour la vérification du dispatch sur une requête isolée) : ce qui
        // compte ici est que cette 6e requête, rejetée, n'a rien dispatché.
        self::assertCount(0, $this->asyncTransport()->getSent());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testTheEndpointIsNotSubjectToTheDoubleSubmitCookieCsrfCheck(): void
    {
        $client = $this->createClientWithFreshRateLimiter();

        // Aucun cookie XSRF-TOKEN ni header X-XSRF-TOKEN envoyés : si
        // /api/contact n'était pas exclu de CsrfCookieRequestSubscriber, la
        // requête échouerait en 403 avant même d'atteindre la validation.
        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]));

        self::assertResponseStatusCodeSame(202);
    }
}
