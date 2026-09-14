<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\TestCredentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class CpgUserAdministratorTest extends TestCase
{
    public function testDeleteRemovesAnotherUser(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        // Cible sans ROLE_SUPER : la garde anti-lockout ne doit même pas
        // interroger le dépôt sur le décompte.
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::once())->method('remove')->with($targetUser);

        $administrator = new CpgUserAdministrator($repository, self::createStub(UserPasswordHasherInterface::class));

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    public function testDeleteThrowsWhenTargetIsTheLastSuperAdmin(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(1);
        $repository->expects(self::never())->method('remove');

        $administrator = new CpgUserAdministrator($repository, self::createStub(UserPasswordHasherInterface::class));

        $this->expectException(CannotDeleteLastSuperAdminException::class);

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    public function testDeleteRemovesSuperAdminWhenAnotherSuperAdminRemains(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(2);
        $repository->expects(self::once())->method('remove')->with($targetUser);

        $administrator = new CpgUserAdministrator($repository, self::createStub(UserPasswordHasherInterface::class));

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    /**
     * La garde porte sur une *valeur* d'identifiant, pas sur une instance :
     * l'id vient de l'URL, donc d'un `Uuid` fraîchement reconstruit. Un `===`
     * entre deux objets `Uuid` de même valeur répondrait faux et laisserait
     * un super-admin se supprimer lui-même — d'où la comparaison par
     * `equals()`, et ce test qui la pin.
     */
    public function testDeleteThrowsWhenTargetingOwnAccount(): void
    {
        $actingUser = $this->user('super');
        $sameIdFromTheUrl = Uuid::fromString($actingUser->getId()->toRfc4122());

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneById');
        $repository->expects(self::never())->method('remove');

        $administrator = new CpgUserAdministrator($repository, self::createStub(UserPasswordHasherInterface::class));

        $this->expectException(CannotDeleteOwnAccountException::class);

        $administrator->delete($sameIdFromTheUrl, $actingUser);
    }

    public function testDeleteThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->user('super');

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new CpgUserAdministrator($repository, self::createStub(UserPasswordHasherInterface::class));

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->delete(Uuid::v7(), $actingUser);
    }

    public function testChangePasswordHashesAndSavesNewPassword(): void
    {
        $user = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($user->getId())->willReturn($user);
        $repository->expects(self::once())->method('save')->with($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, TestCredentials::variant('new'))
            ->willReturn('new-hashed-password');

        $administrator = new CpgUserAdministrator($repository, $hasher);

        $administrator->changePassword($user->getId(), TestCredentials::variant('new'));

        self::assertSame('new-hashed-password', $user->getPassword());
    }

    public function testChangePasswordThrowsWhenUserNotFound(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $hasher = self::createStub(UserPasswordHasherInterface::class);

        $administrator = new CpgUserAdministrator($repository, $hasher);

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->changePassword(Uuid::v7(), TestCredentials::variant('new'));
    }

    /**
     * Plus de réflexion sur `id` : depuis la spec 0003, le constructeur pose
     * lui-même un UUID v7, distinct pour chaque instance.
     */
    private function user(string $username): CpgUser
    {
        return new CpgUser($username, 'hashed-password');
    }

    private function superUser(string $username): CpgUser
    {
        $user = $this->user($username);
        $user->setRoles([CpgUser::ROLE_SUPER]);

        return $user;
    }
}
