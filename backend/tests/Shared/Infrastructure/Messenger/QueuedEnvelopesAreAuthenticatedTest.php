<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Shared\Infrastructure\Messenger\Fixtures\Probe\UnserializationProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Le câblage d'AuthenticatedEnvelopeSerializer (issue #444) : c'est lui, sous
 * la décoration de Messenger, que les deux transports utilisent. Son
 * comportement est couvert par AuthenticatedEnvelopeSerializerTest.
 */
final class QueuedEnvelopesAreAuthenticatedTest extends KernelTestCase
{
    protected function setUp(): void
    {
        UnserializationProbe::$unserializations = 0;
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function queuedMessages(): iterable
    {
        yield 'invitation' => [new SendAccountInvitationMessage('01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11', 'fr', 1)];
        yield 'contact' => [new SendContactMessageMessage('Jane', 'jane@example.com', 'Bonjour')];
    }

    #[DataProvider('queuedMessages')]
    public function testEveryQueuedMessageCarriesAMacAndIsDecoded(object $message): void
    {
        $encoded = $this->serializer()->encode(new Envelope($message));

        self::assertArrayHasKey(AuthenticatedEnvelopeSerializer::HEADER, $encoded['headers'] ?? []);
        self::assertEquals($message, $this->serializer()->decode($encoded)->getMessage());
    }

    /**
     * Par la chaîne réelle (décoration de Messenger comprise) : une enveloppe
     * altérée n'atteint pas `unserialize()`.
     */
    public function testATamperedEnvelopeIsRefusedBeforeAnyUnserialization(): void
    {
        $message = new stdClass();
        $message->gadget = new UnserializationProbe();
        $encoded = $this->serializer()->encode(new Envelope($message));
        $encoded['body'] = str_replace('gadget', 'forged', $encoded['body']);

        self::assertInstanceOf(MessageDecodingFailedException::class, $this->serializer()->decode($encoded)->getMessage());
        self::assertSame(0, UnserializationProbe::$unserializations);
    }

    /**
     * Première release de #444 : le MAC n'est pas encore exigé. L'issue de
     * suivi qui l'exige retourne cette assertion.
     */
    public function testTheMacIsNotRequiredYet(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->getParameter('app.messenger.require_envelope_mac'));
    }

    private function serializer(): SerializerInterface
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        return self::getContainer()->get('messenger.default_serializer');
    }
}
