<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use App\Tests\Support\HttpJson;
use Doctrine\DBAL\Connection;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parle au serveur MCP comme un client Streamable HTTP : `initialize`, la
 * notification `initialized`, puis des requêtes portant l'identifiant de
 * session que le serveur a rendu.
 *
 * Le transport peut répondre en JSON ou en flux SSE (`text/event-stream`) :
 * {@see self::decodeMcpResponse()} lit les deux, pour que les tests
 * n'assument pas la forme que le SDK choisit.
 */
trait SpeaksMcp
{
    use HttpJson;

    private const string MCP_ENDPOINT = '/api/mcp';

    private const string MCP_PROTOCOL_VERSION = '2025-06-18';

    private const string MCP_SESSION_HEADER = 'Mcp-Session-Id';

    /**
     * Ouvre une session MCP et rend son identifiant.
     *
     * @param array<string, string> $server en-têtes supplémentaires (format `$_SERVER`)
     */
    private function initializeMcpSession(KernelBrowser $client, array $server = []): string
    {
        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ],
        ], $server);
        self::assertResponseIsSuccessful();

        $sessionId = $client->getResponse()->headers->get(self::MCP_SESSION_HEADER);
        self::assertIsString($sessionId, 'initialize doit rendre un identifiant de session.');

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $server);

        return $sessionId;
    }

    /**
     * Envoie une requête JSON-RPC dans la session et rend la réponse décodée.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function mcpCall(KernelBrowser $client, string $sessionId, string $method, array $params = [], array $server = []): array
    {
        $this->postMcp($client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => $method,
            'params' => [] === $params ? new stdClass() : $params,
        ], $server);

        return $this->decodeMcpResponse($client);
    }

    /**
     * Appelle un outil et rend le `result` de la réponse — une erreur d'outil
     * (`isError: true`) en est un ; une erreur de protocole fait échouer le test.
     *
     * @param array<string, string> $arguments
     *
     * @return array<string, mixed>
     */
    private function callMcpTool(KernelBrowser $client, string $sessionId, string $name, array $arguments = []): array
    {
        $response = $this->mcpCall($client, $sessionId, 'tools/call', [
            'name' => $name,
            'arguments' => [] === $arguments ? new stdClass() : $arguments,
        ]);
        self::assertArrayHasKey('result', $response, \sprintf('tools/call « %s » a répondu une erreur de protocole.', $name));
        self::assertIsArray($response['result']);

        /** @var array<string, mixed> $result */
        $result = $response['result'];

        return $result;
    }

    /**
     * L'entrée de `tools/list` qui porte ce nom ; le test échoue si elle n'y
     * est pas.
     *
     * @return array<string, mixed>
     */
    private function listedMcpTool(KernelBrowser $client, string $sessionId, string $name): array
    {
        $response = $this->mcpCall($client, $sessionId, 'tools/list');

        foreach ($response['result']['tools'] as $tool) {
            if ($name === $tool['name']) {
                /** @var array<string, mixed> $tool */
                return $tool;
            }
        }

        self::fail(\sprintf('tools/list n\'annonce pas « %s ».', $name));
    }

    /**
     * Le corps JSON décodé que sert une route GET — l'étalon auquel un outil
     * adossé se compare (ADR 0006 D1).
     *
     * @return array<mixed>
     */
    private function routeBody(KernelBrowser $client, string $path): array
    {
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * Supprime les sessions MCP ouvertes par le test : elles vivent une heure
     * dans la table du cache, un passage en laisserait au suivant.
     */
    private static function forgetMcpSessions(Connection $connection): void
    {
        $connection->executeStatement("DELETE FROM cache_items WHERE item_id LIKE '%mcp-portfolio-%'");
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $server
     */
    private function postMcp(KernelBrowser $client, ?string $sessionId, array $message, array $server = []): void
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
        ];
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $client->request('POST', self::MCP_ENDPOINT, server: $server + $headers, content: self::jsonBody($message));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMcpResponse(KernelBrowser $client): array
    {
        $response = $client->getResponse();
        $body = (string) $response->getContent();

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            $body = self::lastJsonRpcAnswerOf($body);
        }

        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Un flux SSE peut porter des notifications avant la réponse : on garde le
     * dernier événement qui répond à une requête (`result` ou `error`), chaque
     * événement étant lu à part (ses lignes `data:` jointes).
     */
    private static function lastJsonRpcAnswerOf(string $stream): string
    {
        $answer = '';
        foreach (explode("\n\n", str_replace("\r\n", "\n", $stream)) as $event) {
            $data = [];
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }

            $candidate = implode("\n", $data);
            if (str_contains($candidate, '"result"') || str_contains($candidate, '"error"')) {
                $answer = $candidate;
            }
        }

        return $answer;
    }
}
