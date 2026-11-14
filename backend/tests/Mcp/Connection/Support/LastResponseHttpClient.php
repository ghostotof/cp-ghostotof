<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Support;

use LogicException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Décorateur de test (#651) : il garde la dernière réponse que le client
 * décoré a rendue, sans la modifier. Le test peut ainsi lire après coup
 * `getInfo('canceled')` : la preuve que la récupération a bien appelé
 * `cancel()` (et fermé la connexion) au lieu de laisser le flux se vider.
 *
 * Il n'enveloppe pas la réponse : `stream()` reçoit donc l'objet que le client
 * décoré a lui-même rendu, comme en production.
 */
final class LastResponseHttpClient implements HttpClientInterface
{
    private ?ResponseInterface $lastResponse = null;

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->lastResponse = $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    public function lastResponse(): ResponseInterface
    {
        return $this->lastResponse ?? throw new LogicException('Aucune requête n\'a été émise.');
    }
}
