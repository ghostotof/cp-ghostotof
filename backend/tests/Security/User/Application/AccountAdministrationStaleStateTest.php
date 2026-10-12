<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserRoleAdministratorInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ReadsSecurityAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * L'administration d'un compte se décide sur l'état validé en base au moment
 * du verrou, jamais sur ce que la carte d'identité tenait d'avant la requête
 * (issues #551, #552).
 *
 * Pour une requête, le fournisseur de sécurité charge l'acteur et le provider
 * API Platform charge la cible, avant le processor : entre ce chargement et
 * l'écriture, un autre super-administrateur, ou la purge, a pu valider. Ici
 * ce « entre-temps » est une écriture SQL après le chargement, sur la base
 * réelle, le kernel de test tenant lieu de requête ; les mêmes courses sont
 * reproduites à vrais processus par AccountAdministrationConcurrencyTest.
 *
 *  - Issue #552 : le perdant de deux `DELETE` du même compte, ou un `PUT` des
 *    rôles sur un compte supprimé entre-temps, répondait 204 et écrivait une
 *    ligne d'audit (`user-deleted`, `role-changed`) pour une ligne déjà
 *    disparue — Doctrine ne lit pas le nombre de lignes d'un `DELETE` ni d'un
 *    `UPDATE`. Désormais : introuvable (404), et rien dans le journal.
 *  - Issue #551, l'acteur relu : rétrogradé ou supprimé entre le pare-feu et
 *    le verrou, il est refusé (403), rien n'est écrit ni journalisé.
 */
final class AccountAdministrationStaleStateTest extends KernelTestCase
{
    use ReadsSecurityAuditLog;

    private EntityManagerInterface $em;

    private CpgUserRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(CpgUserRepositoryInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testDeletingAnAccountAlreadyDeletedIsANotFoundAndWritesNoAuditLine(): void
    {
        $actor = $this->loadedSuperAdmin('alpha');
        $target = $this->loaded('jane', []);
        $this->deleteInSql($target->getId());

        $this->expectException(CpgUserNotFoundException::class);

        try {
            self::getContainer()->get(CpgUserAdministratorInterface::class)->delete($target->getId(), $actor);
        } finally {
            self::assertSame([], self::securityAuditEvents('user-deleted'), 'Une suppression qui n\'a rien supprimé a écrit `user-deleted` (#552).');
        }
    }

    public function testChangingTheRoleOfAnAccountAlreadyDeletedIsANotFoundAndWritesNoAuditLine(): void
    {
        $actor = $this->loadedSuperAdmin('alpha');
        $target = $this->loaded('jane', []);
        $this->deleteInSql($target->getId());

        $this->expectException(CpgUserNotFoundException::class);

        try {
            self::getContainer()->get(CpgUserRoleAdministratorInterface::class)->setSuperAdmin($target->getId(), true, $actor);
        } finally {
            self::assertSame([], self::securityAuditEvents('role-changed'), 'Un changement de rôle sur une ligne disparue a écrit `role-changed` (#552).');
        }
    }

    /**
     * Le comptage des ROLE_SUPER se faisait sur `findAll()`, donc sur la carte
     * d'identité, où l'acteur est encore ROLE_SUPER : la rétrogradation
     * concurrente de l'acteur passait inaperçue, et la cible, dernier
     * ROLE_SUPER réel, était rétrogradée.
     */
    public function testAnActorDemotedMeanwhileCannotDemoteAnyone(): void
    {
        $actor = $this->loadedSuperAdmin('alpha');
        $target = $this->loadedSuperAdmin('beta');
        $this->demoteInSql($actor->getId());

        $refusal = $this->refusalOf(fn () => self::getContainer()->get(CpgUserRoleAdministratorInterface::class)->setSuperAdmin($target->getId(), false, $actor));

        self::assertInstanceOf(ActingAccountNoLongerSuperAdminException::class, $refusal, \sprintf('Refus attendu, obtenu : %s.', get_debug_type($refusal)));
        self::assertSame(['beta'], $this->storedSuperAdminUsernames(), 'La cible a été rétrogradée par un acteur qui ne l\'était plus : plus aucun ROLE_SUPER (#551).');
        self::assertSame([], self::securityAuditEvents('role-changed'));
    }

    public function testAnActorDemotedMeanwhileCannotDeleteAnyone(): void
    {
        $actor = $this->loadedSuperAdmin('alpha');
        $target = $this->loadedSuperAdmin('beta');
        $this->demoteInSql($actor->getId());

        $refusal = $this->refusalOf(fn () => self::getContainer()->get(CpgUserAdministratorInterface::class)->delete($target->getId(), $actor));

        self::assertInstanceOf(ActingAccountNoLongerSuperAdminException::class, $refusal, \sprintf('Refus attendu, obtenu : %s.', get_debug_type($refusal)));
        self::assertSame(['beta'], $this->storedSuperAdminUsernames(), 'La cible a été supprimée par un acteur qui n\'était plus ROLE_SUPER (#551).');
        self::assertSame([], self::securityAuditEvents('user-deleted'));
    }

    public function testAnActorDeletedMeanwhileCannotAdministerAnyone(): void
    {
        $actor = $this->loadedSuperAdmin('alpha');
        $target = $this->loaded('jane', []);
        $this->deleteInSql($actor->getId());

        $refusal = $this->refusalOf(fn () => self::getContainer()->get(CpgUserRoleAdministratorInterface::class)->setSuperAdmin($target->getId(), true, $actor));

        self::assertInstanceOf(ActingAccountNoLongerSuperAdminException::class, $refusal, \sprintf('Refus attendu, obtenu : %s.', get_debug_type($refusal)));
        self::assertSame([], $this->storedSuperAdminUsernames(), 'Un acteur supprimé a encore promu un compte (#551).');
        self::assertSame([], self::securityAuditEvents('role-changed'));
    }

    /**
     * Issue #551 : la garde compte, en base, les autres ROLE_SUPER **capables de
     * se connecter**. Un ROLE_SUPER encore en attente d'activation (mot de
     * passe vide) ne garde pas le backoffice ouvert : seul autre ROLE_SUPER,
     * il ne sauve pas la cible de la garde. Ici l'acteur lui-même est ce
     * compte en attente — le seul acteur qu'un pare-feu laisse passer est un
     * ROLE_SUPER connecté, donc par HTTP la cible a toujours au moins lui ;
     * le cas d'usage, appelé d'ailleurs, refuse quand même.
     */
    public function testTheLastSuperAdminAbleToSignInCannotBeDeletedWhenTheOnlyOtherOneIsPendingActivation(): void
    {
        $actor = $this->loaded('alpha', [CpgUser::ROLE_SUPER], hashedPassword: '');
        $target = $this->loadedSuperAdmin('beta');

        $refusal = $this->refusalOf(fn () => self::getContainer()->get(CpgUserAdministratorInterface::class)->delete($target->getId(), $actor));

        self::assertInstanceOf(CannotDeleteLastSuperAdminException::class, $refusal, \sprintf('Refus attendu, obtenu : %s.', get_debug_type($refusal)));
        self::assertSame(['alpha', 'beta'], $this->storedSuperAdminUsernames());
        self::assertSame([], self::securityAuditEvents('user-deleted'));
    }

    public function testTheLastSuperAdminAbleToSignInCannotBeDemotedWhenTheOnlyOtherOneIsPendingActivation(): void
    {
        $actor = $this->loaded('alpha', [CpgUser::ROLE_SUPER], hashedPassword: '');
        $target = $this->loadedSuperAdmin('beta');

        $refusal = $this->refusalOf(fn () => self::getContainer()->get(CpgUserRoleAdministratorInterface::class)->setSuperAdmin($target->getId(), false, $actor));

        self::assertInstanceOf(CannotDemoteLastSuperAdminException::class, $refusal, \sprintf('Refus attendu, obtenu : %s.', get_debug_type($refusal)));
        self::assertSame(['alpha', 'beta'], $this->storedSuperAdminUsernames());
        self::assertSame([], self::securityAuditEvents('role-changed'));
    }

    /**
     * @param list<string> $roles
     */
    private function loaded(string $username, array $roles, string $hashedPassword = 'hashed-password'): CpgUser
    {
        $user = new CpgUser($username, $hashedPassword);
        $user->setRoles($roles);
        $this->repository->save($user);
        // Comme pour une requête : l'instance en carte d'identité est celle que
        // le fournisseur de sécurité ou le provider a chargée avant l'écriture
        // concurrente. `save()` l'y a laissée.
        return $user;
    }

    private function loadedSuperAdmin(string $username): CpgUser
    {
        return $this->loaded($username, [CpgUser::ROLE_SUPER]);
    }

    private function deleteInSql(Uuid $id): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $id->toRfc4122()]);
    }

    private function demoteInSql(Uuid $id): void
    {
        $this->em->getConnection()->executeStatement("UPDATE cpg_user SET roles = '[]' WHERE id = :id", ['id' => $id->toRfc4122()]);
    }

    private function refusalOf(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function storedSuperAdminUsernames(): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT username FROM cpg_user WHERE CAST(roles AS jsonb) @> CAST(:role AS jsonb) ORDER BY username',
            ['role' => json_encode([CpgUser::ROLE_SUPER], \JSON_THROW_ON_ERROR)],
        );

        $usernames = [];
        foreach ($rows as $username) {
            self::assertIsString($username);
            $usernames[] = $username;
        }

        return $usernames;
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
