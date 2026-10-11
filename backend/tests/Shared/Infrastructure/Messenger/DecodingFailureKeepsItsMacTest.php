<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Support\SignedEnvelope;
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
 * a perdu son MAC, ce redécodage voit une enveloppe « sans MAC » et la
 * refuse : un refus qui remplace la cause réelle.
 *
 * La chaîne est la vraie : {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
 * du conteneur, réexpédition du réessai par `encode()`, puis le middleware de
 * Messenger. Le sérialiseur authentifiant est construit ici, sous le secret du
 * conteneur ; son câblage dans le conteneur, sous le
 * SigningSerializer de Messenger, est couvert par
 * QueuedEnvelopesAreAuthenticatedTest.
 */
final class DecodingFailureKeepsItsMacTest extends KernelTestCase
{
    /** La cause que PhpSerializer donne d'un corps qui n'est pas une enveloppe. */
    private const string UNDECODABLE_CAUSE = 'Could not decode message into an Envelope.';

    private const string WRONG_MAC_CAUSE = 'Enveloppe Messenger au MAC invalide refusée (issue #444).';

    /**
     * Chaque façon d'échouer : le corps à signer, le MAC qui remplace le vrai
     * s'il en faut un faux, la cause attendue.
     *
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function failures(): iterable
    {
        // Un objet sérialisé valide, mais pas une enveloppe : la forme la plus
        // simple d'un corps que PhpSerializer refuse après `unserialize()`.
        $undecodable = addslashes(serialize(new stdClass()));

        yield 'signée, corps texte' => [$undecodable, null, self::UNDECODABLE_CAUSE];
        // Ce que PhpSerializer écrit d'un corps qui n'est pas de l'UTF-8.
        yield 'signée, corps base64' => [base64_encode($undecodable), null, self::UNDECODABLE_CAUSE];
        // Le cas qu'un attaquant contrôle : il suffit d'écrire dans la file.
        yield 'MAC faux' => [$undecodable, str_repeat('0', 64), self::WRONG_MAC_CAUSE];
    }

    public function testTheFailureKeepsItsCauseAtReception(): void
    {
        $serializer = $this->serializer();

        $received = $serializer->decode($this->signed(addslashes(serialize(new stdClass()))));

        $this->assertTheMiddlewareRaises(self::UNDECODABLE_CAUSE, $serializer, $received);
    }

    /**
     * L'enveloppe gardée est celle reçue, à l'octet près : PhpSerializer, lui,
     * garde l'en-tête retiré et, pour un corps base64, le corps décodé, que le
     * MAC ne couvre pas.
     */
    #[DataProvider('failures')]
    public function testTheFailureKeepsTheEnvelopeAsReceived(string $body, ?string $forgedMac, string $cause): void
    {
        $encoded = $this->signed($body, $forgedMac);

        $failure = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $failure);
        self::assertSame($cause, $failure->getMessage());
        self::assertSame($encoded, $failure->encodedEnvelope);
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
    #[DataProvider('failures')]
    public function testTheFailureKeepsItsCauseAndItsStampsAfterRetries(string $body, ?string $forgedMac, string $cause): void
    {
        $serializer = $this->serializer();
        $encoded = $this->signed($body, $forgedMac);
        $received = $serializer->decode($encoded);

        $retried = $serializer->decode($serializer->encode($received->with(new RedeliveryStamp(1))));
        $retriedTwice = $serializer->decode($serializer->encode($retried->with(new RedeliveryStamp(2))));

        $this->assertTheMiddlewareRaises($cause, $serializer, $retriedTwice);
        self::assertSame(2, $retriedTwice->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertCount(2, $retriedTwice->all(RedeliveryStamp::class));
        $carried = $retriedTwice->getMessage();
        self::assertInstanceOf(MessageDecodingFailedException::class, $carried);
        self::assertSame($encoded, $carried->encodedEnvelope);
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

    private function serializer(): AuthenticatedEnvelopeSerializer
    {
        $inner = self::getContainer()->get('messenger.transport.native_php_serializer');

        return new AuthenticatedEnvelopeSerializer($inner, $this->secret());
    }

    /**
     * `$body` signé par l'application, son MAC remplacé par `$forgedMac` s'il
     * est donné.
     *
     * @return array{body: string, headers?: array<string, string>}
     */
    private function signed(string $body, ?string $forgedMac = null): array
    {
        $encoded = SignedEnvelope::of($body, $this->secret());
        if (null !== $forgedMac) {
            $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = $forgedMac;
        }

        return $encoded;
    }

    private function secret(): string
    {
        return self::getContainer()->getParameter('kernel.secret');
    }
}
