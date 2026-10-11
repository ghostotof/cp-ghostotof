<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application\Message;

use App\Security\User\Application\Message\MalformedAccountInvitationMessageException;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Tests\Support\SerializedInvitationMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #434 : le message porte la génération d'invitation qu'il sert, et se
 * restaure lui-même à la désérialisation (PhpSerializer ne repasse pas par le
 * constructeur). Le traitement par le handler est couvert par
 * SendAccountInvitationStaleMessageTest, sur la base réelle.
 */
final class SendAccountInvitationMessageTest extends TestCase
{
    private const string USER_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    public function testASerializedMessageKeepsItsInvitationGeneration(): void
    {
        $restored = unserialize(serialize(new SendAccountInvitationMessage(self::USER_ID, 'en', 3)));

        self::assertInstanceOf(SendAccountInvitationMessage::class, $restored);
        self::assertSame(self::USER_ID, $restored->userId);
        self::assertSame('en', $restored->locale);
        self::assertSame(3, $restored->invitationGeneration);
    }

    public function testAMessageServesTheGenerationItCarries(): void
    {
        self::assertSame(2, (new SendAccountInvitationMessage(self::USER_ID, 'fr', 2))->servedGeneration());
    }

    /**
     * Un message mis en file avant #434 ne porte pas de génération. Il sert
     * la première : la migration a posé 1 à tout compte déjà invité, si bien
     * qu'une relance faite depuis l'écarte, et qu'il reste traité sinon.
     */
    public function testAMessageQueuedBeforeTheGenerationExistedServesTheFirstOne(): void
    {
        $legacy = unserialize(SerializedInvitationMessage::withoutGeneration(self::USER_ID));

        self::assertInstanceOf(SendAccountInvitationMessage::class, $legacy);
        self::assertNull($legacy->invitationGeneration);
        self::assertSame(1, $legacy->servedGeneration());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function malformedPayloads(): iterable
    {
        $userId = 's:6:"userId";s:36:"'.self::USER_ID.'";';
        $locale = 's:6:"locale";s:2:"fr";';
        $generation = 's:20:"invitationGeneration";i:1;';

        yield 'userId absent' => [2, $locale.$generation];
        yield 'userId entier' => [3, 's:6:"userId";i:42;'.$locale.$generation];
        yield 'userId hors UUID' => [3, 's:6:"userId";s:3:"abc";'.$locale.$generation];
        yield 'locale absente' => [2, $userId.$generation];
        yield 'locale entière' => [3, $userId.'s:6:"locale";i:1;'.$generation];
        yield 'locale inconnue' => [3, $userId.'s:6:"locale";s:2:"de";'.$generation];
        yield 'génération en chaîne' => [3, $userId.$locale.'s:20:"invitationGeneration";s:1:"1";'];
    }

    /**
     * Un message altéré dans la file n'est jamais traité à moitié, et
     * l'exception ne cite aucune valeur lue.
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedSerializedMessageIsRefused(int $propertyCount, string $properties): void
    {
        try {
            unserialize(SerializedInvitationMessage::of($propertyCount, $properties));
            self::fail('MalformedAccountInvitationMessageException aurait dû être levée.');
        } catch (MalformedAccountInvitationMessageException $exception) {
            self::assertStringNotContainsString(self::USER_ID, $exception->getMessage());
            self::assertStringNotContainsString('abc', $exception->getMessage());
            self::assertStringNotContainsString('"de"', $exception->getMessage());
        }
    }
}
