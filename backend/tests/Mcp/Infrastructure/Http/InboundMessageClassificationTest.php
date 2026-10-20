<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\InboundMessageRefusal;
use App\Mcp\Infrastructure\Http\InboundMessageRefusalListener;
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
 * SDK (#671) : le listener doit tenir pour une réponse exactement ce que
 * `MessageFactory` du SDK 0.8.1 range en `Response` ou `Error` — ce que
 * `Protocol::handleResponse()` enregistre en session.
 *
 * Le SDK lit `error`, puis `result` (par `isset`, une valeur `null` ne compte
 * pas), **avant** `method`. Le listener exigeait l'absence de `method` : une
 * requête portant un résultat passait, et le SDK la rangeait en session.
 *
 * Chaque ligne fixe, écrite à la main, ce que la vraie `MessageFactory` fait
 * du message. Le jour où le SDK change d'ordre de lecture, cette colonne
 * tombe : il faut alors réaligner le listener, pas la table.
 */
final class InboundMessageClassificationTest extends TestCase
{
    private const string RESPONSE = 'réponse';

    private const string REQUEST = 'requête ou notification';

    private const string INVALID = 'invalide';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'requête portant un résultat (#671)' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","result":{}}', self::RESPONSE];
        yield 'requête portant une erreur (#671)' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","error":{"code":-32000,"message":"no"}}', self::RESPONSE];
        yield 'requête portant un résultat en liste vide' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","result":[]}', self::RESPONSE];
        yield 'résultat seul' => ['{"jsonrpc":"2.0","id":1,"result":{}}', self::RESPONSE];
        yield 'erreur seule' => ['{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"no"}}', self::RESPONSE];
        yield 'erreur sans identifiant' => ['{"jsonrpc":"2.0","id":null,"error":{"code":-32000,"message":"no"}}', self::RESPONSE];
        yield 'requête portant un résultat nul' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":null}', self::REQUEST];
        yield 'requête portant une erreur nulle' => ['{"jsonrpc":"2.0","id":1,"method":"ping","error":null}', self::REQUEST];
        yield 'requête' => ['{"jsonrpc":"2.0","id":1,"method":"ping"}', self::REQUEST];
        yield 'notification' => ['{"jsonrpc":"2.0","method":"notifications/initialized"}', self::REQUEST];
        yield 'résultat qui n’est pas un objet' => ['{"jsonrpc":"2.0","id":1,"method":"ping","result":"x"}', self::INVALID];
        yield 'résultat nul sans méthode' => ['{"jsonrpc":"2.0","id":1,"result":null}', self::INVALID];
    }

    #[DataProvider('messages')]
    public function testTheSdkClassifiesTheMessageAsWritten(string $message, string $sdkKind): void
    {
        self::assertSame($sdkKind, $this->sdkKindOf($message), 'La classification de MessageFactory a changé : réaligner InboundMessageRefusalListener::isResponse() sur elle (#671).');
    }

    /**
     * Ce que le SDK prend pour une réponse est refusé ; ce qu'il sert comme
     * requête ou notification ne l'est pas. Un message que le SDK refuse
     * lui-même (invalide) n'est ni servi ni enregistré : le refus peut le
     * prendre ou le laisser.
     */
    #[DataProvider('messages')]
    public function testTheListenerRefusesExactlyWhatTheSdkTakesForAResponse(string $message, string $sdkKind): void
    {
        $refused = $this->refusedAsClientResponse($message);

        match ($sdkKind) {
            self::RESPONSE => self::assertTrue($refused, 'Le SDK enregistrerait ce message comme réponse : il doit être refusé.'),
            self::REQUEST => self::assertFalse($refused, 'Le SDK sert ce message : il ne doit pas être refusé comme réponse.'),
            default => self::assertSame(self::INVALID, $sdkKind),
        };
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

        new InboundMessageRefusalListener()($event);

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
