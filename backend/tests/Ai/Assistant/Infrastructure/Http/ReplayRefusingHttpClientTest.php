<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\Http;

use App\Ai\Assistant\Infrastructure\Http\ReplayRefusingHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Le bridge Scaleway enveloppe son client dans un EventSourceHttpClient qui,
 * sur une coupure en plein flux, renvoie la même requête après 10 s : pour un
 * POST de chat completion, c'est une seconde génération facturée, hors quota,
 * ajoutée à la réponse partielle (constaté le 2026-09-26). Ce décorateur
 * refuse ce renvoi.
 */
final class ReplayRefusingHttpClientTest extends TestCase
{
    private int $sent = 0;

    public function testTheSameRequestIsSentOnlyOnce(): void
    {
        $client = $this->client();

        $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":1}']);

        try {
            $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":1}']);
            self::fail('Le renvoi aurait dû être refusé.');
        } catch (TransportExceptionInterface) {
        }

        self::assertSame(1, $this->sent);
    }

    public function testADifferentBodyIsADifferentRequest(): void
    {
        $client = $this->client();

        $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":1}']);
        $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":2}']);

        self::assertSame(2, $this->sent);
    }

    /** Un worker longue durée, ou deux requêtes HTTP successives, repartent de zéro. */
    public function testResetForgetsWhatWasSent(): void
    {
        $client = $this->client();

        $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":1}']);
        $client->reset();
        $client->request('POST', 'https://api.example.test/v1/chat/completions', ['body' => '{"q":1}']);

        self::assertSame(2, $this->sent);
    }

    private function client(): ReplayRefusingHttpClient
    {
        return new ReplayRefusingHttpClient(new MockHttpClient(function (): MockResponse {
            ++$this->sent;

            return new MockResponse('{}');
        }));
    }
}
