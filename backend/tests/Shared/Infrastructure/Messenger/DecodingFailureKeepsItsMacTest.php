<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Issue #465 : un échec de décodage d'une enveloppe signée garde son MAC.
 *
 * Le sérialiseur du transport rend l'échec comme une enveloppe
 * ({@see MessageDecodingFailedException::wrap()}) qui garde l'enveloppe
 * encodée ; DecodeFailedMessageMiddleware la redécode avec ce même
 * sérialiseur, à la réception puis après chaque réessai. Si l'enveloppe gardée
 * a perdu son MAC, ce redécodage voit une enveloppe « sans MAC » : un faux
 * warning en mode transition, un refus qui remplace la cause réelle une fois
 * le MAC exigé.
 *
 * La chaîne est la vraie : PhpSerializer du conteneur, réexpédition du
 * réessai par `encode()`, puis le middleware de Messenger.
 */
final class DecodingFailureKeepsItsMacTest extends KernelTestCase
{
    /** La cause que PhpSerializer donne d'un corps qui n'est pas une enveloppe. */
    private const string ORIGINAL_CAUSE = 'Could not decode message into an Envelope.';

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'transition' => [false];
        yield 'MAC exigé' => [true];
    }

    #[DataProvider('modes')]
    public function testTheFailureKeepsItsCauseAtReception(bool $requireMac): void
    {
        $logs = new TestHandler();
        $serializer = $this->serializer($requireMac, $logs);

        $received = $serializer->decode($this->signedUndecodableEnvelope());

        $this->assertTheMiddlewareRaises(self::ORIGINAL_CAUSE, $serializer, $received);
        self::assertFalse($logs->hasRecordThatContains('sans MAC', Level::Warning), 'Un échec de décodage signé a été pris pour une enveloppe sans MAC.');
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function modesAndFailures(): iterable
    {
        foreach (['transition' => false, 'MAC exigé' => true] as $mode => $requireMac) {
            yield $mode.', signée, corps texte' => [$requireMac, 'texte'];
            yield $mode.', signée, corps base64' => [$requireMac, 'base64'];
            // Le cas qu'un attaquant contrôle : il suffit d'écrire dans la file.
            yield $mode.', MAC faux' => [$requireMac, 'MAC faux'];
        }
    }

    /**
     * Le réessai réexpédie l'échec par `encode()`, avec ses tampons, puis le
     * relit : cet échec-là est le message transporté, pas un échec du
     * décodage en cours. Il revient tel quel — l'enveloppe encodée d'origine,
     * MAC compris, et les tampons dont dépendent le compte des réessais et
     * `messenger:failed:*`. Ré-enveloppé, il perdait son RedeliveryStamp
     * (réessais sans fin, jusqu'à épuiser la mémoire du worker) et emboîtait
     * chaque réessai dans le suivant.
     */
    #[DataProvider('modesAndFailures')]
    public function testTheFailureKeepsItsCauseAndItsStampsAfterRetries(bool $requireMac, string $failure): void
    {
        $logs = new TestHandler();
        $serializer = $this->serializer($requireMac, $logs);
        $encoded = $this->signedUndecodableEnvelope('base64' === $failure);
        if ('MAC faux' === $failure) {
            $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = str_repeat('0', 64);
        }
        $cause = 'MAC faux' === $failure ? 'Enveloppe Messenger au MAC invalide refusée (issue #444).' : self::ORIGINAL_CAUSE;
        $received = $serializer->decode($encoded);

        $retried = $serializer->decode($serializer->encode($received->with(new RedeliveryStamp(1))));
        $retriedTwice = $serializer->decode($serializer->encode($retried->with(new RedeliveryStamp(2))));

        $this->assertTheMiddlewareRaises($cause, $serializer, $retriedTwice);
        self::assertSame(2, $retriedTwice->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertCount(2, $retriedTwice->all(RedeliveryStamp::class));
        $carried = $retriedTwice->getMessage();
        self::assertInstanceOf(MessageDecodingFailedException::class, $carried);
        self::assertSame($encoded, $carried->encodedEnvelope);
        self::assertFalse($logs->hasRecordThatContains('sans MAC', Level::Warning), 'Un échec de décodage signé a été pris pour une enveloppe sans MAC.');
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function modesAndEncodings(): iterable
    {
        foreach (['transition' => false, 'MAC exigé' => true] as $mode => $requireMac) {
            yield $mode.', corps texte' => [$requireMac, false];
            yield $mode.', corps base64' => [$requireMac, true];
        }
    }

    /**
     * L'enveloppe gardée est celle reçue, à l'octet près : PhpSerializer, lui,
     * garde l'en-tête retiré et, pour un corps base64 (ce qu'il écrit d'un
     * corps qui n'est pas de l'UTF-8), le corps décodé, que le MAC ne couvre
     * pas.
     */
    #[DataProvider('modesAndEncodings')]
    public function testTheFailureKeepsTheEnvelopeAsReceived(bool $requireMac, bool $base64): void
    {
        $encoded = $this->signedUndecodableEnvelope($base64);

        $failure = $this->serializer($requireMac, new TestHandler())->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $failure);
        self::assertSame(self::ORIGINAL_CAUSE, $failure->getMessage());
        self::assertSame($encoded, $failure->encodedEnvelope);
    }

    private function assertTheMiddlewareRaises(string $cause, SerializerInterface $serializer, Envelope $received): void
    {
        self::assertInstanceOf(MessageDecodingFailedException::class, $received->getMessage());
        $middleware = new DecodeFailedMessageMiddleware(new ServiceLocator(['async' => static fn (): SerializerInterface => $serializer]));

        try {
            $middleware->handle($received->with(new ReceivedStamp('async')), new StackMiddleware());
            self::fail('Le middleware a décodé une enveloppe indécodable.');
        } catch (MessageDecodingFailedException $failure) {
            self::assertSame($cause, $failure->getMessage());
        }
    }

    private function serializer(bool $requireMac, TestHandler $logs): AuthenticatedEnvelopeSerializer
    {
        $inner = self::getContainer()->get('messenger.transport.native_php_serializer');
        $secret = self::getContainer()->getParameter('kernel.secret');

        return new AuthenticatedEnvelopeSerializer($inner, $secret, $requireMac, new Logger('messenger', [$logs]));
    }

    /**
     * Un corps signé par l'application, objet sérialisé valide mais qui n'est
     * pas une enveloppe : la forme la plus simple d'un message que
     * PhpSerializer refuse après `unserialize()`.
     *
     * @return array{body: string, headers?: array<string, string>}
     */
    private function signedUndecodableEnvelope(bool $base64 = false): array
    {
        $body = addslashes(serialize(new stdClass()));

        // Le sérialiseur authentifiant signe ce que son sérialiseur interne
        // produit : un double qui rend le corps fabriqué le fait signer.
        $inner = self::createStub(SerializerInterface::class);
        $inner->method('encode')->willReturn(['body' => $base64 ? base64_encode($body) : $body]);
        $secret = self::getContainer()->getParameter('kernel.secret');

        $encoded = new AuthenticatedEnvelopeSerializer($inner, $secret, true, new Logger('messenger'))->encode(new Envelope(new stdClass()));
        self::assertNotSame([], $encoded['headers'] ?? [], 'Le corps fabriqué doit être signé.');

        return $encoded;
    }
}
