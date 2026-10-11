<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application\Message;

use App\Security\User\Application\Message\MalformedAccountInvitationMessageException;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #434 : le message porte l'instant de l'invitation qu'il sert, et se
 * restaure lui-même à la désérialisation (PhpSerializer ne repasse pas par le
 * constructeur). Le traitement par le handler est couvert par
 * SendAccountInvitationStaleMessageTest, sur la base réelle.
 */
final class SendAccountInvitationMessageTest extends TestCase
{
    private const string USER_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    public function testASerializedMessageKeepsItsInvitationInstant(): void
    {
        $restored = unserialize(serialize(new SendAccountInvitationMessage(self::USER_ID, 'en', 1_791_709_200)));

        self::assertInstanceOf(SendAccountInvitationMessage::class, $restored);
        self::assertSame(self::USER_ID, $restored->userId);
        self::assertSame('en', $restored->locale);
        self::assertSame(1_791_709_200, $restored->invitedAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'userId absent' => ['s:6:"locale";s:2:"fr";s:9:"invitedAt";i:1;'];
        yield 'userId entier' => ['s:6:"userId";i:42;s:6:"locale";s:2:"fr";s:9:"invitedAt";i:1;'];
        yield 'locale absente' => ['s:6:"userId";s:3:"abc";s:9:"invitedAt";i:1;'];
        yield 'invitedAt en chaîne' => ['s:6:"userId";s:3:"abc";s:6:"locale";s:2:"fr";s:9:"invitedAt";s:10:"1791709200";'];
    }

    /**
     * Un message altéré dans la file n'est jamais traité à moitié, et
     * l'exception ne cite aucune valeur lue.
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedSerializedMessageIsRefused(string $properties): void
    {
        // Chaque propriété sérialisée compte deux `;` : sa clé et sa valeur.
        $count = intdiv(substr_count($properties, ';'), 2);

        try {
            unserialize(\sprintf(
                'O:%d:"%s":%d:{%s}',
                \strlen(SendAccountInvitationMessage::class),
                SendAccountInvitationMessage::class,
                $count,
                $properties,
            ));
            self::fail('MalformedAccountInvitationMessageException aurait dû être levée.');
        } catch (MalformedAccountInvitationMessageException $exception) {
            self::assertStringNotContainsString('abc', $exception->getMessage());
            self::assertStringNotContainsString('1791709200', $exception->getMessage());
        }
    }
}
