<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Shared\Infrastructure\Messenger\EnvelopeMacRefusedException;
use App\Shared\Infrastructure\Messenger\EnvelopeTooLargeException;
use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\TestKeys;
use App\Tests\Support\UnserializationProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Toute enveloppe mise en file est authentifiée avant le moindre
 * `unserialize()` de son corps (issue #444).
 *
 * Les files sont relues par PhpSerializer, soit `unserialize()` sans liste de
 * classes : écrire dans RabbitMQ ou dans la table du `failure_transport`
 * vaudrait injection d'objet dans le worker, quel que soit le type du
 * message. UnserializationProbe compte ses désérialisations : un gadget
 * réel s'exécuterait au même endroit.
 */
final class AuthenticatedEnvelopeSerializerTest extends TestCase
{
    protected function setUp(): void
    {
        UnserializationProbe::$unserializations = 0;
    }

    public function testAnEnvelopeItEncodedIsDecoded(): void
    {
        $serializer = $this->serializer();
        $message = new stdClass();
        $message->text = 'bonjour';

        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertEquals($message, $decoded);
    }

    /**
     * Une valeur changée dans le corps, qui reste un objet sérialisé valide.
     */
    public function testATamperedEnvelopeIsRefusedBeforeAnyUnserialization(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->carrying(new UnserializationProbe())));
        self::assertStringContainsString('gadget', $encoded['body']);
        $encoded['body'] = str_replace('gadget', 'forged', $encoded['body']);

        $decoded = $serializer->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Le cas que la signature par type de Messenger laissait passer : une
     * enveloppe sans MAC, d'un type quelconque, qui porte un objet. Refusée
     * sans condition depuis l'issue #450, qui a supprimé la transition de #444.
     */
    public function testAnEnvelopeWithoutMacIsRefusedBeforeAnyUnserialization(): void
    {
        $decoded = $this->serializer()->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    public function testAWrongMacIsRefusedBeforeAnyUnserialization(): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = str_repeat('0', 64);

        $decoded = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringMacs(): iterable
    {
        yield 'entier' => [42];
        yield 'tableau' => [['mac']];
        yield 'booléen' => [true];
    }

    /**
     * AMQP et le JSON du transport Doctrine peuvent rendre un en-tête qui
     * n'est pas une chaîne : refusé comme un MAC faux, sans erreur de type qui
     * ferait tomber le worker (aucun décorateur ne la rattrape ici).
     */
    #[DataProvider('nonStringMacs')]
    public function testAMacThatIsNotAStringIsRefused(mixed $mac): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = $mac;

        // @phpstan-ignore argument.type (un transport peut livrer un en-tête qui n'est pas une chaîne, malgré le contrat : c'est ce que le test éprouve)
        $decoded = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * La clé est dérivée : un HMAC du corps sous APP_SECRET brut — celui
     * qu'émettrait une autre fonction du site — ne vaut pas MAC d'enveloppe.
     */
    public function testTheMacIsNotAnHmacUnderTheRawSecret(): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = hash_hmac('sha256', $encoded['body'], TestKeys::material());

        $decoded = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
    }

    /**
     * Issue #450 : la clé change de contexte avec la fin de la transition de
     * #444. Pendant celle-ci, une enveloppe forgée sans MAC était décodée,
     * puis re-signée au réessai — gadget compris — et rangée dans `failed`
     * avec un MAC valable : aucune signature de cette ère ne doit plus être
     * reconnue, sans dépendre d'une inspection des files.
     */
    public function testAnEnvelopeSignedUnderTheTransitionKeyIsRefused(): void
    {
        $encoded = $this->forgedWithoutMac();
        $transitionKey = SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/messenger-envelope/v1');
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = hash_hmac('sha256', $encoded['body'], $transitionKey);

        $decoded = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Le refus ne cite que sa cause, jamais le corps.
     */
    public function testTheRefusalQuotesNothingOfTheEnvelope(): void
    {
        $decoded = $this->serializer()->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertStringNotContainsString('gadget', $decoded->getMessage());
        self::assertStringNotContainsString('UnserializationProbe', $decoded->getMessage());
    }

    /**
     * Issue #469 : un corps au-delà de la borne est refusé avant son MAC, même
     * valable, et définitivement (EnvelopeTooLargeException est
     * irrécupérable par construction). Le HMAC de plusieurs Mo, le `unserialize()` et la copie du
     * corps dans `failed` pouvaient faire boucler le worker (`--memory-limit`
     * de 120 Mo dans un conteneur de 192 Mi).
     */
    public function testABodyOverTheBoundIsRefusedEvenWithAValidMac(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->paddedTo(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1)));
        self::assertSame(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1, $this->encodedSize($encoded));

        $refusal = $serializer->decode($encoded)->getMessage();

        self::assertInstanceOf(EnvelopeTooLargeException::class, $refusal);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    public function testABodyAtTheBoundIsDecoded(): void
    {
        $serializer = $this->serializer();
        $message = $this->paddedTo(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES);
        $encoded = $serializer->encode(new Envelope($message));
        self::assertSame(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES, $this->encodedSize($encoded));

        self::assertEquals($message, $serializer->decode($encoded)->getMessage());
    }

    /**
     * Le refus part dans `failed` : il ne doit pas y recopier le corps, ni le
     * citer dans son message, qui ne donne que la taille.
     */
    public function testTheRefusalKeepsNothingOfTheOversizedBody(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->paddedTo(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1)));

        $refusal = $serializer->decode($encoded)->getMessage();

        self::assertInstanceOf(EnvelopeTooLargeException::class, $refusal);
        self::assertSame('', $refusal->encodedEnvelope['body']);
        self::assertLessThan(200, \strlen(serialize($refusal->encodedEnvelope)));
        self::assertStringContainsString((string) (AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1), $refusal->getMessage());
        self::assertStringNotContainsString('gadget', $refusal->getMessage());
    }

    /**
     * DecodeFailedMessageMiddleware redécode l'enveloppe que garde le refus, à
     * la réception puis à chaque relecture de `failed` : elle doit redonner le
     * même refus, pas un « sans MAC » qui en masquerait la cause.
     */
    public function testTheRefusalDecodesAgainAsTheSameRefusal(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->paddedTo(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1)));
        $refusal = $serializer->decode($encoded)->getMessage();
        self::assertInstanceOf(EnvelopeTooLargeException::class, $refusal);

        /** @var array{body: string, headers?: array<string, string>} $kept l'exception ne type pas son enveloppe */
        $kept = $refusal->encodedEnvelope;
        $again = $serializer->decode($kept)->getMessage();

        self::assertInstanceOf(EnvelopeTooLargeException::class, $again);
        self::assertSame($refusal->getMessage(), $again->getMessage());
    }

    /**
     * Le marqueur de taille ne vaut que sur un corps vide, et que pour un
     * nombre : sur un vrai corps, il ne dispense pas du MAC.
     */
    public function testTheSizeMarkerNeverStandsInForAMac(): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][EnvelopeTooLargeException::SIZE_HEADER] = '12';

        $decoded = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(EnvelopeMacRefusedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Le vecteur de #469 passait aussi par les en-têtes : un corps d'un octet
     * et un en-tête de plusieurs Mo, que le refus de MAC gardait et recopiait
     * dans `failed` (relecture de #469). La borne vaut pour l'enveloppe
     * encodée entière.
     */
    public function testOversizedHeadersAreRefusedBeforeTheMac(): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers']['X-Junk'] = str_repeat('h', AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES);

        $refusal = $this->serializer()->decode($encoded)->getMessage();

        self::assertInstanceOf(EnvelopeTooLargeException::class, $refusal);
        self::assertArrayNotHasKey('X-Junk', $refusal->encodedEnvelope['headers'] ?? []);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Un marqueur forgé à une taille sous la borne n'est pas un refus de
     * taille : l'enveloppe, vide et sans MAC, est refusée comme telle.
     */
    public function testASizeMarkerUnderTheBoundIsNotASizeRefusal(): void
    {
        $decoded = $this->serializer()->decode(['body' => '', 'headers' => [EnvelopeTooLargeException::SIZE_HEADER => '12']])->getMessage();

        self::assertInstanceOf(EnvelopeMacRefusedException::class, $decoded);
    }

    /**
     * Sans `zend.exception_ignore_args` (le conteneur de dev), la trace de
     * l'exception garde les arguments de `decode()`, soit le corps : il
     * repartait dans `failed` par elle (relecture de #469, mesuré : 800 Ko
     * pour un corps de 300 Ko).
     */
    public function testTheRefusalKeepsNoTraceOfTheBodyWhenArgumentsAreKept(): void
    {
        $serializer = $this->serializer();
        $encoded = $serializer->encode(new Envelope($this->paddedTo(AuthenticatedEnvelopeSerializer::MAX_ENVELOPE_BYTES + 1)));
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $refusal = $serializer->decode($encoded)->getMessage();
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        self::assertInstanceOf(EnvelopeTooLargeException::class, $refusal);
        self::assertLessThan(16 * 1024, \strlen(serialize($refusal)));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        new AuthenticatedEnvelopeSerializer(new PhpSerializer(), '');
    }

    private function serializer(): AuthenticatedEnvelopeSerializer
    {
        return new AuthenticatedEnvelopeSerializer(new PhpSerializer(), TestKeys::material());
    }

    /**
     * Ce qu'écrirait un attaquant dans la file : une enveloppe PhpSerializer
     * sans MAC, d'un type que rien ne signe, qui porte la sonde.
     *
     * @return array{body: string, headers?: array<string, string>}
     */
    private function forgedWithoutMac(): array
    {
        /** @var array{body: string, headers?: array<string, string>} */
        return new PhpSerializer()->encode(new Envelope($this->carrying(new UnserializationProbe())));
    }

    /**
     * Un message porteur de la sonde, bourré pour que son enveloppe signée,
     * corps et en-têtes (noms compris), fasse exactement `$bytes` octets (des
     * `x`, que PhpSerializer n'échappe pas).
     */
    private function paddedTo(int $bytes): stdClass
    {
        $message = $this->carrying(new UnserializationProbe());
        $message->padding = '';

        // La longueur de la chaîne s'écrit en chiffres dans `serialize()` : le
        // bourrage s'ajuste jusqu'à tomber juste (deux tours suffisent).
        do {
            $gap = $bytes - $this->encodedSize($this->serializer()->encode(new Envelope($message)));
            $message->padding = str_repeat('x', \strlen($message->padding) + $gap);
        } while (0 !== $gap);

        return $message;
    }

    /**
     * @param array{body: string, headers?: array<string, string>} $encoded
     */
    private function encodedSize(array $encoded): int
    {
        $bytes = \strlen($encoded['body']);
        foreach ($encoded['headers'] ?? [] as $name => $value) {
            $bytes += \strlen($name) + \strlen($value);
        }

        return $bytes;
    }

    private function carrying(UnserializationProbe $probe): stdClass
    {
        $message = new stdClass();
        $message->gadget = $probe;

        return $message;
    }
}
