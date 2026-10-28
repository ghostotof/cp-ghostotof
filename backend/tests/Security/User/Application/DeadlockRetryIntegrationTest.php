<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Application\CpgUserRoleAdministrator;
use App\Security\User\Application\DeadlockRetryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Tests\Security\User\Support\ReadsStoredSuperAdmins;
use App\Tests\Support\DeadlockingOnceRevoker;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La relance unique sur interblocage (S3 de la seconde relecture de #650, CR4)
 * sur la **base réelle**, les vrais verrous et la vraie relance : la seule part
 * simulée est le `40P01`, levé par la révocation des connexions MCP au premier
 * essai — **après** que le mot de passe ou le rôle ont été écrits et validés au
 * point de sauvegarde, donc quand le squelette des verrous a fermé
 * l'EntityManager (issue #549). C'est ce que les doubles des tests unitaires ne
 * peuvent pas montrer : le second essai tourne sur un EntityManager remis à
 * neuf, et non sur celui que le premier a fermé.
 *
 * Le cycle réel, en processus séparés, est celui de
 * `AccountWideRevocationDeadlockTest` (le changement de mot de passe). La
 * rétrogradation passe par le verrou d'administration, dont le second essai
 * relit la cible **et** l'acteur par leur identifiant : ce test le prouve ici.
 *
 * Mutations mesurées (S3) : la relance retirée — rouge (l'exception remonte) ;
 * la relance sans `resetManager()` — rouge (`EntityManagerClosed` au second
 * essai) ; la relance sur toute exception — voir `DoctrineDeadlockRetryTest`.
 */
final class DeadlockRetryIntegrationTest extends KernelTestCase
{
    use ReadsSecurityAuditLog;
    use ReadsStoredSuperAdmins;

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

    public function testADeadlockedPasswordChangeSucceedsOnTheSecondAttemptOnAFreshEntityManager(): void
    {
        $account = $this->saved('jane', []);
        $before = $this->storedCredentials($account);
        $revoker = new DeadlockingOnceRevoker($this->container(AccountMcpConnectionsRevokerInterface::class), failures: 1);

        $this->passwordAdministrator($revoker)->changePassword($account->getId(), TestCredentials::variant('new'));

        self::assertSame(2, $revoker->calls, 'Un interblocage, puis un second essai.');
        $after = $this->storedCredentials($account);
        self::assertNotSame($before['password'], $after['password'], 'Le mot de passe n\'a pas changé au second essai.');
        self::assertSame($before['version'] + 1, $after['version'], 'La version des identifiants doit avancer exactement une fois.');
        self::assertCount(1, self::securityAuditEvents('password-changed'), 'Le journal suit le seul COMMIT abouti : une ligne.');
    }

    public function testADeadlockedPasswordChangeFailingAgainLeavesTheAccountUntouched(): void
    {
        $account = $this->saved('jane', []);
        $before = $this->storedCredentials($account);
        $revoker = new DeadlockingOnceRevoker($this->container(AccountMcpConnectionsRevokerInterface::class), failures: 2);

        try {
            $this->passwordAdministrator($revoker)->changePassword($account->getId(), TestCredentials::variant('new'));
            self::fail('Le second interblocage aurait dû remonter.');
        } catch (DeadlockException) {
            // Attendu : comme avant S3, un 500 pour la requête.
        }

        self::assertSame(2, $revoker->calls, 'Exactement deux essais.');
        self::assertSame($before, $this->storedCredentials($account), 'Deux transactions annulées n\'ont rien laissé.');
        self::assertSame([], self::securityAuditEvents('password-changed'));
    }

    public function testADeadlockedDemotionSucceedsOnTheSecondAttemptAndIsAuditedOnce(): void
    {
        $actor = $this->saved('alpha', [CpgUser::ROLE_SUPER]);
        $target = $this->saved('beta', [CpgUser::ROLE_SUPER]);
        $revoker = new DeadlockingOnceRevoker($this->container(AccountMcpConnectionsRevokerInterface::class), failures: 1);

        $this->roleAdministrator($revoker)->setSuperAdmin($target->getId(), false, $actor);

        self::assertSame(2, $revoker->calls);
        self::assertSame(['alpha'], $this->storedSuperAdminUsernames(), 'La cible aurait dû être rétrogradée au second essai.');
        self::assertCount(1, self::securityAuditEvents('role-changed'), 'Le journal suit le seul COMMIT abouti : une ligne.');
    }

    private function passwordAdministrator(AccountMcpConnectionsRevokerInterface $revoker): CpgUserAdministrator
    {
        return new CpgUserAdministrator(
            $this->repository,
            $this->container(UserPasswordHasherInterface::class),
            $this->container(SecurityAuditLoggerInterface::class),
            $this->container(PasswordSetupTokenRepositoryInterface::class),
            $this->container(AccountLockInterface::class),
            $this->container(AccountAdministrationLockInterface::class),
            $revoker,
            $this->container(ClockInterface::class),
            $this->container(DeadlockRetryInterface::class),
        );
    }

    private function roleAdministrator(AccountMcpConnectionsRevokerInterface $revoker): CpgUserRoleAdministrator
    {
        return new CpgUserRoleAdministrator(
            $this->repository,
            $this->container(SecurityAuditLoggerInterface::class),
            $this->container(AccountAdministrationLockInterface::class),
            $revoker,
            $this->container(DeadlockRetryInterface::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function container(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @param list<string> $roles
     */
    private function saved(string $username, array $roles): CpgUser
    {
        $user = new CpgUser($username, TestCredentials::variant('old'));
        $user->setRoles($roles);
        $this->repository->save($user);

        return $user;
    }

    /**
     * Le mot de passe, sa date et la version des identifiants, tels que la base les porte.
     *
     * @return array{password: string, version: int}
     */
    private function storedCredentials(CpgUser $account): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT password, credentials_version FROM cpg_user WHERE id = :id', ['id' => $account->getId()->toRfc4122()]);
        self::assertIsArray($row);
        self::assertIsString($row['password']);
        self::assertIsInt($row['credentials_version']);

        return ['password' => $row['password'], 'version' => $row['credentials_version']];
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
