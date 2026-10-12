<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserRoleAdministratorInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Deux super-administrateurs qui se rétrogradent, ou se suppriment, l'un
 * l'autre au même instant en laissent toujours un (issue #551).
 *
 * La garde « jamais le dernier ROLE_SUPER » comptait puis agissait, sans
 * verrou ni transaction : chacune des deux requêtes comptait deux
 * super-administrateurs, passait la garde, et les deux écritures validaient —
 * plus aucun ROLE_SUPER, le backoffice entier inaccessible. L'entrelacement,
 * sur le modèle de InviteUsernameConcurrencyTest :
 *
 *  1. le fils (Fixtures/administer-account.php), agissant comme `beta`,
 *     charge `alpha` et `beta` puis attend la barrière ;
 *  2. `alpha`, dans ce processus, rétrograde (ou supprime) `beta` ; au
 *     `preFlush` de son écriture — garde passée, ligne pas encore écrite —, le
 *     test relâche le fils, qui rétrograde (ou supprime) `alpha` ;
 *  3. le test attend que le fils ait **fini** ou soit **bloqué sur un verrou** ;
 *  4. `alpha` termine, puis le fils.
 *
 * Sans le verrou d'administration, le fils termine pendant la pause et les
 * deux écritures valident. Avec, il attend le COMMIT de `alpha`, relit son
 * acteur `beta` — plus ROLE_SUPER — et est refusé : `alpha` reste.
 */
final class AccountAdministrationConcurrencyTest extends KernelTestCase
{
    private const int TIMEOUT_SECONDS = 30;

    private const string ADMINISTER_SCRIPT = __DIR__.'/../Fixtures/administer-account.php';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testTwoSuperAdminsDemotingEachOtherLeaveOne(): void
    {
        [$alpha, $beta] = $this->twoSuperAdmins();
        $concurrent = $this->interleaveBeforeNextFlush('beta', 'alpha', 'demote');

        self::getContainer()->get(CpgUserRoleAdministratorInterface::class)->setSuperAdmin($beta->getId(), false, $alpha);
        $concurrent->wait();

        // Le sort du fils d'abord : un fils qui n'a pas pu se mettre en place
        // doit montrer sa sortie d'erreur, pas accuser le verrou.
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(['alpha'], $this->storedSuperAdminUsernames(), 'Les deux rétrogradations ont validé : plus aucun ROLE_SUPER (#551).');
        self::assertSame(1, $concurrent->getExitCode(), 'La rétrogradation concurrente aurait dû être refusée : '.$concurrent->getOutput().$concurrent->getErrorOutput());
        self::assertStringContainsString('refused '.ActingAccountNoLongerSuperAdminException::class, $concurrent->getOutput());
    }

    public function testTwoSuperAdminsDeletingEachOtherLeaveOne(): void
    {
        [$alpha, $beta] = $this->twoSuperAdmins();
        $concurrent = $this->interleaveBeforeNextFlush('beta', 'alpha', 'delete');

        self::getContainer()->get(CpgUserAdministratorInterface::class)->delete($beta->getId(), $alpha);
        $concurrent->wait();

        // Le sort du fils d'abord : un fils qui n'a pas pu se mettre en place
        // doit montrer sa sortie d'erreur, pas accuser le verrou.
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(['alpha'], $this->storedSuperAdminUsernames(), 'Les deux suppressions ont validé : plus aucun ROLE_SUPER (#551).');
        self::assertSame(1, $concurrent->getExitCode(), 'La suppression concurrente aurait dû être refusée : '.$concurrent->getOutput().$concurrent->getErrorOutput());
        self::assertStringContainsString('refused '.ActingAccountNoLongerSuperAdminException::class, $concurrent->getOutput());
    }

    /**
     * Démarre le fils et le relâche au prochain `preFlush` ({@see FlushInterleaving}).
     */
    private function interleaveBeforeNextFlush(string $actorUsername, string $targetUsername, string $action): Process
    {
        $barrier = ProcessBarrier::hold('administer-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::ADMINISTER_SCRIPT, $barrier->path(), $actorUsername, $targetUsername, $action);
        $concurrent->start();
        FlushInterleaving::releaseBeforeNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    /**
     * `alpha` et `beta`, ROLE_SUPER tous deux, capables de se connecter,
     * chargés comme pour une requête (carte d'identité de ce processus).
     *
     * @return array{CpgUser, CpgUser}
     */
    private function twoSuperAdmins(): array
    {
        $repository = self::getContainer()->get(CpgUserRepositoryInterface::class);
        foreach (['alpha', 'beta'] as $username) {
            $user = new CpgUser($username, 'hashed-password');
            $user->setRoles([CpgUser::ROLE_SUPER]);
            $repository->save($user);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $alpha = $repository->findOneByUsername('alpha');
        $beta = $repository->findOneByUsername('beta');
        self::assertNotNull($alpha);
        self::assertNotNull($beta);

        return [$alpha, $beta];
    }

    /**
     * Lu en base, jamais par la carte d'identité : c'est elle que le test
     * accuse.
     *
     * @return list<string>
     */
    private function storedSuperAdminUsernames(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
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
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
