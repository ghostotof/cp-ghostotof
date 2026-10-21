<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\InboundMessageRefusal;
use App\Mcp\Infrastructure\Http\InboundMessageRefusalListener;
use App\Mcp\Infrastructure\Http\ServerAnswers;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request as JsonRpcRequest;
use Mcp\Schema\JsonRpc\Response as JsonRpcResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Lie le refus des réponses du client (#644, refus n°3) à la classification du
 * SDK (#671) : le listener refuse tout message que {@see MessageFactory} du
 * SDK 0.8.1 classe en `Response` ou `Error` — ceux que
 * {@see \Mcp\Server\Protocol::handleResponse()} range en session, sauf une
 * erreur sans identifiant, qu'il journalise seulement — et laisse passer ce
 * qu'il sert comme requête ou notification.
 *
 * Le SDK lit `error`, puis `result` (par `isset`, une valeur `null` ne compte
 * pas), **avant** `method`. Le listener exigeait l'absence de `method` : une
 * requête portant un résultat passait, et le SDK la rangeait en session.
 *
 * Chaque ligne fixe à la main ce que la vraie `MessageFactory` fait du
 * message, et ce que décide le listener. Le jour où le SDK classe autrement,
 * le premier test tombe : corriger alors la colonne du SDK, puis le second
 * test impose de réaligner le listener.
 */
final class InboundMessageClassificationTest extends TestCase
{
    private const string RESPONSE = 'réponse';

    private const string REQUEST = 'requête ou notification';

    private const string INVALID = 'invalide';

    /**
     * Le message, ce qu'en fait le SDK, et si le listener le refuse comme
     * réponse du client.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function messages(): iterable
    {
        yield 'requête portant un résultat (#671)' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","result":{}}', self::RESPONSE, true];
        yield 'requête portant une erreur (#671)' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","error":{"code":-32000,"message":"no"}}', self::RESPONSE, true];
        yield 'requête portant un résultat en liste vide' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","result":[]}', self::RESPONSE, true];
        yield 'lot d’un message portant un résultat' => ['[{"jsonrpc":"2.0","id":"a1","method":"ping","result":{}}]', self::RESPONSE, true];
        yield 'résultat seul' => ['{"jsonrpc":"2.0","id":1,"result":{}}', self::RESPONSE, true];
        yield 'erreur seule' => ['{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"no"}}', self::RESPONSE, true];
        yield 'erreur sans identifiant' => ['{"jsonrpc":"2.0","id":null,"error":{"code":-32000,"message":"no"}}', self::RESPONSE, true];
        yield 'requête portant un résultat nul' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":null}', self::REQUEST, false];
        yield 'requête portant une erreur nulle' => ['{"jsonrpc":"2.0","id":1,"method":"ping","error":null}', self::REQUEST, false];
        yield 'requête' => ['{"jsonrpc":"2.0","id":1,"method":"ping"}', self::REQUEST, false];
        yield 'notification' => ['{"jsonrpc":"2.0","method":"notifications/initialized"}', self::REQUEST, false];
        // `isset` retient une valeur fausse mais non nulle : le SDK entre dans
        // la branche des réponses, puis refuse le message comme invalide. Le
        // listener le refuse avant lui, sans identifiant.
        yield 'requête portant une erreur fausse' => ['{"jsonrpc":"2.0","id":1,"method":"ping","error":false}', self::INVALID, true];
        yield 'requête portant un résultat zéro' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":0}', self::INVALID, true];
        yield 'requête portant un résultat vide' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":""}', self::INVALID, true];
        yield 'requête portant un résultat texte' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":"x"}', self::INVALID, true];
        // Ni `method`, ni valeur non nulle : le SDK le refuse lui-même.
        yield 'résultat nul sans méthode' => ['{"jsonrpc":"2.0","id":1,"result":null}', self::INVALID, false];
        yield 'erreur nulle sans méthode' => ['{"jsonrpc":"2.0","id":1,"error":null}', self::INVALID, false];
    }

    #[DataProvider('messages')]
    public function testTheSdkClassifiesTheMessageAsWritten(string $message, string $sdkKind, bool $refused): void
    {
        self::assertSame($sdkKind, $this->sdkKindOf($message), 'La classification de MessageFactory a changé : corriger la colonne du SDK, puis réaligner InboundMessageRefusalListener::isResponse() (#671).');
    }

    /**
     * Le listener décide comme la table, et la table refuse ce que le SDK
     * prend pour une réponse, jamais ce qu'il sert. Un message que le SDK
     * refuse lui-même (invalide) n'est ni servi ni enregistré : sa ligne fixe
     * seulement ce que décide le listener.
     */
    #[DataProvider('messages')]
    public function testTheListenerRefusesWhatTheSdkTakesForAResponse(string $message, string $sdkKind, bool $refused): void
    {
        if (self::RESPONSE === $sdkKind) {
            self::assertTrue($refused, 'Le SDK enregistrerait ce message comme réponse : il doit être refusé.');
        }
        if (self::REQUEST === $sdkKind) {
            self::assertFalse($refused, 'Le SDK sert ce message : il ne doit pas être refusé comme réponse.');
        }

        self::assertSame($refused, $this->refusedAsClientResponse($message));
    }

    private function sdkKindOf(string $message): string
    {
        $created = MessageFactory::make()->create($message);
        self::assertCount(1, $created);

        return match (true) {
            $created[0] instanceof JsonRpcResponse, $created[0] instanceof Error => self::RESPONSE,
            $created[0] instanceof JsonRpcRequest, $created[0] instanceof Notification => self::REQUEST,
            $created[0] instanceof InvalidInputMessageException => self::INVALID,
            default => self::fail(\sprintf('Classe inattendue : %s.', $created[0]::class)),
        };
    }

    private function refusedAsClientResponse(string $message): bool
    {
        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/mcp', 'POST', content: $message),
            HttpKernelInterface::MAIN_REQUEST,
        );

        new InboundMessageRefusalListener(new ServerAnswers())($event);

        $response = $event->getResponse();
        if (null === $response) {
            return false;
        }

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['error']);

        return InboundMessageRefusal::ClientResponse->message() === $body['error']['message'];
    }
}
