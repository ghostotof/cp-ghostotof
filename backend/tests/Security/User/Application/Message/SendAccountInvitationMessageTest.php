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
        yield 'génération flottante' => [3, $userId.$locale.'s:20:"invitationGeneration";d:1;'];
        yield 'génération booléenne' => [3, $userId.$locale.'s:20:"invitationGeneration";b:1;'];
        // Un compte sur invitation a une génération d'au moins 1 (markInvited() part
        // de 0) : 0 ou moins ne vient pas de CpgUserInviter.
        yield 'génération à zéro' => [3, $userId.$locale.'s:20:"invitationGeneration";i:0;'];
        yield 'génération négative' => [3, $userId.$locale.'s:20:"invitationGeneration";i:-1;'];
    }

    /**
     * Un message altéré dans la file n'est jamais traité à moitié, et
     * l'exception ne cite aucune valeur lue.
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedSerializedMessageIsRefused(int $propertyCount, string $properties): void
    {
        $exception = $this->refusalOf($propertyCount, $properties);

        self::assertSame(MalformedAccountInvitationMessageException::fromSerializedData()->getMessage(), $exception->getMessage());
        self::assertStringNotContainsString(self::USER_ID, $exception->getMessage());
        self::assertStringNotContainsString('abc', $exception->getMessage());
        self::assertStringNotContainsString('"de"', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function payloadsWithoutGeneration(): iterable
    {
        $userIdAndLocale = 's:6:"userId";s:36:"'.self::USER_ID.'";s:6:"locale";s:2:"fr";';

        yield 'génération absente' => [2, $userIdAndLocale];
        yield 'génération nulle' => [3, $userIdAndLocale.'s:20:"invitationGeneration";N;'];
    }

    /**
     * Le format d'avant #434, toléré jusqu'à la v0.23.0, est refusé depuis
     * que les files en sont vides (issue #448) : le handler ne saurait pas
     * s'il sert une invitation dépassée. Son refus se distingue de celui d'un
     * message altéré : l'opérateur qui le trouve dans `failed` relance
     * l'invitation, il n'enquête pas sur une file compromise.
     */
    #[DataProvider('payloadsWithoutGeneration')]
    public function testAMessageWithoutGenerationIsRefusedAsTheFormerFormat(int $propertyCount, string $properties): void
    {
        $exception = $this->refusalOf($propertyCount, $properties);

        self::assertSame(MalformedAccountInvitationMessageException::withoutGeneration()->getMessage(), $exception->getMessage());
        self::assertStringNotContainsString(self::USER_ID, $exception->getMessage());
    }

    /**
     * Un message à la fois sans génération et altéré ailleurs est d'abord un
     * message altéré.
     */
    public function testAMessageWithoutGenerationAndAnInvalidUserIdIsRefusedAsTampered(): void
    {
        $exception = $this->refusalOf(2, 's:6:"userId";s:3:"abc";s:6:"locale";s:2:"fr";');

        self::assertSame(MalformedAccountInvitationMessageException::fromSerializedData()->getMessage(), $exception->getMessage());
    }

    private function refusalOf(int $propertyCount, string $properties): MalformedAccountInvitationMessageException
    {
        try {
            unserialize(SerializedInvitationMessage::of($propertyCount, $properties));
        } catch (MalformedAccountInvitationMessageException $exception) {
            return $exception;
        }

        self::fail('MalformedAccountInvitationMessageException aurait dû être levée.');
    }
}
