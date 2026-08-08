<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CpgUser;
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
}
