<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Shared\Infrastructure\Messenger\MissingEnvelopeKeyException;
use App\Tests\Shared\Infrastructure\Messenger\Fixtures\Probe\UnserializationProbe;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private const string SECRET = 'un-secret-de-test-de-trente-deux-octets';

    protected function setUp(): void
    {
        UnserializationProbe::$unserializations = 0;
    }

    public function testAnEnvelopeItEncodedIsDecoded(): void
    {
        $serializer = $this->serializer(requireMac: true);
        $message = new stdClass();
        $message->text = 'bonjour';

        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertEquals($message, $decoded);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'transition' => [false];
        yield 'MAC exigé' => [true];
    }

    /**
     * Une valeur changée dans le corps, qui reste un objet sérialisé valide.
     */
    #[DataProvider('modes')]
    public function testATamperedEnvelopeIsRefusedBeforeAnyUnserialization(bool $requireMac): void
    {
        $serializer = $this->serializer($requireMac);
        $encoded = $serializer->encode(new Envelope($this->carrying(new UnserializationProbe())));
        self::assertStringContainsString('gadget', $encoded['body']);
        $encoded['body'] = str_replace('gadget', 'forged', $encoded['body']);

        $decoded = $serializer->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Le cas que la signature par type de Messenger laissait passer : une
     * enveloppe sans MAC, d'un type quelconque, qui porte un objet.
     */
    public function testAnEnvelopeWithoutMacIsRefusedBeforeAnyUnserializationOnceTheMacIsRequired(): void
    {
        $decoded = $this->serializer(requireMac: true)->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Première release : une enveloppe sans MAC (mise en file par l'ancien
     * code, avant ou pendant le rollout, ou après un retour arrière) reste
     * traitée, et le dit.
     */
    public function testAnEnvelopeWithoutMacIsStillDecodedDuringTheTransition(): void
    {
        $logs = new TestHandler();

        $decoded = $this->serializer(requireMac: false, logger: new Logger('messenger', [$logs]))->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertTrue($logs->hasRecordThatContains('sans MAC', Level::Warning));
    }

    /**
     * Un MAC présent mais faux n'est jamais une enveloppe de l'ancien code :
     * refusé dans les deux modes.
     */
    #[DataProvider('modes')]
    public function testAWrongMacIsRefusedInBothModes(bool $requireMac): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = str_repeat('0', 64);

        $decoded = $this->serializer($requireMac)->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * @return iterable<string, array{bool, mixed}>
     */
    public static function nonStringMacs(): iterable
    {
        foreach (['transition' => false, 'MAC exigé' => true] as $mode => $requireMac) {
            yield $mode.', entier' => [$requireMac, 42];
            yield $mode.', tableau' => [$requireMac, ['mac']];
            yield $mode.', booléen' => [$requireMac, true];
        }
    }

    /**
     * AMQP et le JSON du transport Doctrine peuvent rendre un en-tête qui
     * n'est pas une chaîne : refusé comme un MAC faux, sans erreur de type qui
     * ferait tomber le worker (aucun décorateur ne la rattrape ici).
     */
    #[DataProvider('nonStringMacs')]
    public function testAMacThatIsNotAStringIsRefused(bool $requireMac, mixed $mac): void
    {
        $encoded = $this->forgedWithoutMac();
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = $mac;

        // @phpstan-ignore argument.type (un transport peut livrer un en-tête qui n'est pas une chaîne, malgré le contrat : c'est ce que le test éprouve)
        $decoded = $this->serializer($requireMac)->decode($encoded)->getMessage();

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
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = hash_hmac('sha256', $encoded['body'], self::SECRET);

        $decoded = $this->serializer(requireMac: true)->decode($encoded)->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
    }

    /**
     * Le refus ne cite que sa cause, jamais le corps.
     */
    public function testTheRefusalQuotesNothingOfTheEnvelope(): void
    {
        $decoded = $this->serializer(requireMac: true)->decode($this->forgedWithoutMac())->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertStringNotContainsString('gadget', $decoded->getMessage());
        self::assertStringNotContainsString('UnserializationProbe', $decoded->getMessage());
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(MissingEnvelopeKeyException::class);

        new AuthenticatedEnvelopeSerializer(new PhpSerializer(), '', true, new NullLogger());
    }

    private function serializer(bool $requireMac, ?Logger $logger = null): AuthenticatedEnvelopeSerializer
    {
        return new AuthenticatedEnvelopeSerializer(new PhpSerializer(), self::SECRET, $requireMac, $logger ?? new NullLogger());
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
