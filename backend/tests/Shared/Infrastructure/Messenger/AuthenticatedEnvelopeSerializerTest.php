<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Tests\Shared\Infrastructure\Messenger\Fixtures\Probe\UnserializationProbe;
use App\Tests\Support\TestKeys;
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
     * Le refus ne cite que sa cause, jamais le corps.
     */
    public function testTheRefusalQuotesNothingOfTheEnvelope(): void
    {
        $decoded = $this->serializer()->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertStringNotContainsString('gadget', $decoded->getMessage());
        self::assertStringNotContainsString('UnserializationProbe', $decoded->getMessage());
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

    private function carrying(UnserializationProbe $probe): stdClass
    {
        $message = new stdClass();
        $message->gadget = $probe;

        return $message;
    }
}
