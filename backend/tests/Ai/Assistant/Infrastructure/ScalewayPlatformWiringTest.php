<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fige le câblage de l'agent `career_assistant` sur Scaleway (spec 0005 D2).
 *
 * symfony/ai-bundle 0.13.0 ignore l'option `http_client` d'une plateforme
 * Scaleway déclarée dans ai.yaml : elle partirait par le client par défaut,
 * sans le timeout de 40 s ni `max_redirects: 0` qu'exige l'ADR 0004. La
 * plateforme est donc déclarée dans services.yaml avec le client scoped
 * `ai.scaleway.http_client`. Ce test remplace le client concret derrière ce
 * scope par un MockHttpClient : si la plateforme repassait par `http_client`,
 * le client scoped n'aurait plus de consommateur, Symfony le retirerait du
 * conteneur et le remplacement échouerait — le test casse avant tout appel.
 *
 * Aucun appel ne sort : le vrai bridge (URL, en-têtes, corps, conversion de la
 * réponse) est exercé, seul le transport est simulé.
 */
final class ScalewayPlatformWiringTest extends KernelTestCase
{
    /** Service concret derrière le client scoped `ai.scaleway.http_client` (cf. framework.yaml). */
    private const string SCALEWAY_HTTP_CLIENT_INNER = 'ai.scaleway.http_client.scoping.inner';

    /** Valeur forcée par phpunit.dist.xml. */
    private const string TEST_API_KEY = 'scw-test-not-a-real-key';

    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $requests = [];

    public function testAgentCallsScalewayThroughTheDedicatedScopedClient(): void
    {
        $agent = $this->bootWithMockedTransport();

        $result = $agent->call(new MessageBag(Message::ofUser('Quel est son parcours ?')));

        self::assertSame('Réponse simulée.', $result->getResult()->getContent());
        self::assertCount(1, $this->requests);

        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.scaleway.ai/v1/chat/completions', $request['url']);
        self::assertContains('Authorization: Bearer '.self::TEST_API_KEY, $this->headers($request['options']));

        // Bornes du client dédié (framework.yaml) : elles n'arrivent jusqu'ici
        // que si la requête est bien passée par le scope `ai.scaleway.http_client`.
        self::assertEqualsWithDelta(40.0, $request['options']['timeout'], 0.0);
        self::assertSame(0, $request['options']['max_redirects']);
    }

    public function testRequestBodyCarriesTheConfiguredModelBoundsAndSystemPrompt(): void
    {
        $agent = $this->bootWithMockedTransport();

        // L'exécution est paresseuse : la requête ne part qu'à la lecture du résultat.
        $agent->call(new MessageBag(Message::ofUser('Quel est son parcours ?')))->getResult();

        $body = $this->decodedBody($this->requests[0]['options']);

        self::assertSame('mistral-small-3.2-24b-instruct-2506', $body['model']);
        self::assertSame(1024, $body['max_tokens']);

        // Spec D3 : aucun paramètre d'échantillonnage en v1.
        foreach (['temperature', 'top_p', 'top_k'] as $samplingOption) {
            self::assertArrayNotHasKey($samplingOption, $body);
        }

        // Spec 0005 : aucun outil exposé au modèle.
        self::assertArrayNotHasKey('tools', $body);

        $messages = $body['messages'];
        self::assertIsArray($messages);
        self::assertIsArray($messages[0]);
        self::assertSame('system', $messages[0]['role']);
        self::assertIsString($messages[0]['content']);
        self::assertStringStartsWith('You are the career assistant', $messages[0]['content']);
    }

    private function bootWithMockedTransport(): AgentInterface
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->set(self::SCALEWAY_HTTP_CLIENT_INNER, new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse($this->chatCompletion('Réponse simulée.'), [
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            },
        ));

        return $container->get('ai.agent.career_assistant');
    }

    /** Réponse au format Chat Completions (compatible OpenAI) de Scaleway. */
    private function chatCompletion(string $content): string
    {
        return json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 0,
            'model' => 'mistral-small-3.2-24b-instruct-2506',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function headers(array $options): array
    {
        $headers = $options['headers'] ?? [];
        self::assertIsArray($headers);

        return array_values(array_filter($headers, is_string(...)));
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<mixed>
     */
    private function decodedBody(array $options): array
    {
        $body = $options['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
