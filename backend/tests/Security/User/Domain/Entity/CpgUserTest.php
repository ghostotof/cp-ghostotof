<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Entity;

use App\Security\User\Domain\Entity\CpgUser;
use PHPUnit\Framework\TestCase;

final class CpgUserTest extends TestCase
{
    public function testUserIdentifierIsUsername(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertSame('jane', $user->getUserIdentifier());
        self::assertSame('jane', $user->getUsername());
    }

    public function testRolesAlwaysIncludeRoleUser(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRoleUserIsNotDuplicatedWhenAlreadyPresent(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testPasswordCanBeUpgraded(): void
    {
        $user = new CpgUser('jane', 'old-hash');
        $user->setPassword('new-hash');

        self::assertSame('new-hash', $user->getPassword());
    }

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        $user->eraseCredentials();

        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testANewUserHasNoEmailNorInvitationAndIsNotPendingActivation(): void
    {
        // Un compte fraîchement construit (voie CLI) est utilisable d'emblée :
        // il n'a pas de cycle invitation/activation, donc n'est pas "en attente".
        $user = new CpgUser('jane', 'hashed-password');

        self::assertNull($user->getEmail());
        self::assertNull($user->getInvitedAt());
        self::assertFalse($user->isPendingActivation());
    }

    public function testEmailCanBeSet(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->setEmail('jane@example.com');

        self::assertSame('jane@example.com', $user->getEmail());
    }

    public function testMarkInvitedRecordsTheTimestampWithoutActivating(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $invitedAt = new \DateTimeImmutable('2026-09-03 12:00:00');

        $user->markInvited($invitedAt);

        self::assertSame($invitedAt, $user->getInvitedAt());
        self::assertTrue($user->isPendingActivation());
    }

    public function testMarkActivatedClearsThePendingState(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->markInvited(new \DateTimeImmutable('2026-09-03 12:00:00'));

        $user->markActivated(new \DateTimeImmutable('2026-09-04 09:30:00'));

        self::assertFalse($user->isPendingActivation());
    }
}
