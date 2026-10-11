<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Security\User\Application\Message\MalformedAccountInvitationMessageException;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Tests\Support\SerializedInvitationMessage;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Issue #448 : un message d'invitation sans génération, lu dans une file,
 * échoue au décodage par la chaîne réelle du conteneur
 * (AuthenticatedEnvelopeSerializer, puis PhpSerializer) — il devient un
 * MessageDecodingFailedException, que DecodeFailedMessageMiddleware lève avant
 * tout handler, au lieu de faire tomber le worker.
 *
 * L'enveloppe est signée avec la vraie clé : le refus tient au format du
 * message, pas au MAC, et le test ne dépend pas du mode de transition de #444.
 */
final class InvitationEnvelopeDecodingTest extends KernelTestCase
{
    private const string USER_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    /**
     * Le témoin : la même fabrication, au format courant, est décodée. Sans
     * lui, un refus du MAC passerait pour celui du format.
     */
    public function testASignedEnvelopeOfTheCurrentFormatIsDecoded(): void
    {
        $decoded = $this->queueSerializer()->decode($this->signedEnvelopeCarrying(null))->getMessage();

        self::assertEquals(new SendAccountInvitationMessage(self::USER_ID, 'fr', 1), $decoded);
    }

    public function testASignedEnvelopeWithoutGenerationFailsToDecode(): void
    {
        $legacyMessage = SerializedInvitationMessage::of(2, \sprintf('s:6:"userId";s:36:"%s";s:6:"locale";s:2:"fr";', self::USER_ID));

        $decoded = $this->queueSerializer()->decode($this->signedEnvelopeCarrying($legacyMessage))->getMessage();

        self::assertInstanceOf(MessageDecodingFailedException::class, $decoded);
        self::assertStringContainsString(MalformedAccountInvitationMessageException::withoutGeneration()->getMessage(), $decoded->getMessage());
    }

    /**
     * Ce que PhpSerializer écrirait pour une enveloppe d'invitation, le
     * message remplacé par `$serializedMessage` s'il est donné, signé comme
     * le signerait l'application.
     *
     * @return array{body: string, headers?: array<string, string>}
     */
    private function signedEnvelopeCarrying(?string $serializedMessage): array
    {
        $message = new SendAccountInvitationMessage(self::USER_ID, 'fr', 1);
        $envelope = serialize(new Envelope($message));
        if (null !== $serializedMessage) {
            $envelope = str_replace(serialize($message), $serializedMessage, $envelope);
            self::assertStringContainsString($serializedMessage, $envelope);
        }

        // Le sérialiseur authentifiant signe ce que son sérialiseur interne
        // produit : un double qui rend le corps fabriqué le fait signer.
        $inner = self::createStub(SerializerInterface::class);
        $inner->method('encode')->willReturn(['body' => addslashes($envelope)]);

        return new AuthenticatedEnvelopeSerializer($inner, self::getContainer()->getParameter('kernel.secret'), true, new NullLogger())->encode(new Envelope(new stdClass()));
    }

    private function queueSerializer(): SerializerInterface
    {
        return self::getContainer()->get('messenger.default_serializer');
    }
}
