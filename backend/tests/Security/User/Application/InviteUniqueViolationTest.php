<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserInviter;
use App\Security\User\Application\UsernameAllocationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que la contrainte unique dit, sur la base réelle (issue #364).
 *
 * Les vérifications de `CpgUserInviter` (adresse, puis identifiant libre)
 * sont faites ici par un dépôt qui ne voit rien : c'est le cas d'une écriture
 * concurrente validée entre la vérification et l'enregistrement, que seule la
 * contrainte unique arrête. Le refus doit nommer ce qui est réellement pris —
 * PostgreSQL nomme l'index violé dans son message, que ce test lit tel que
 * le pilote le rend. Dire « adresse déjà utilisée » pour un identifiant pris
 * envoyait l'administrateur chercher un compte qui n'existe pas.
 */
final class InviteUniqueViolationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAUsernameTakenBehindTheCheckIsNotReportedAsAnEmailAlreadyUsed(): void
    {
        $this->storeAccount('jean', 'jean@a.example');

        $this->expectException(UsernameAlreadyUsedException::class);

        $this->inviterBlindToConcurrentWrites()->invite('jean@b.example', Locale::FR);
    }

    public function testAnEmailTakenBehindTheCheckIsStillReportedAsAnEmailAlreadyUsed(): void
    {
        $this->storeAccount('someone-else', 'jean@a.example');

        $this->expectException(EmailAlreadyUsedException::class);

        $this->inviterBlindToConcurrentWrites()->invite('jean@a.example', Locale::FR);
    }

    private function inviterBlindToConcurrentWrites(): CpgUserInviter
    {
        $repository = $this->repositoryBlindToConcurrentWrites();

        return new CpgUserInviter(
            $repository,
            new UsernameGenerator($repository),
            self::getContainer()->get(MessageBusInterface::class),
            new MockClock('2026-10-12 12:00:00'),
            self::getContainer()->get(SecurityAuditLoggerInterface::class),
            self::getContainer()->get(UsernameAllocationLockInterface::class),
        );
    }

    /**
     * Le vrai dépôt, sauf ses deux lectures d'unicité, qui ne trouvent rien :
     * la ligne en conflit a été validée après elles.
     */
    private function repositoryBlindToConcurrentWrites(): CpgUserRepositoryInterface
    {
        $real = self::getContainer()->get(CpgUserRepositoryInterface::class);

        return new readonly class($real) implements CpgUserRepositoryInterface {
            public function __construct(private CpgUserRepositoryInterface $real)
            {
            }

            public function findOneByUsername(#[SensitiveParameter] string $username): ?CpgUser
            {
                return null;
            }

            public function findOneByEmail(string $email): ?CpgUser
            {
                return null;
            }

            public function findOneById(Uuid $id): ?CpgUser
            {
                return $this->real->findOneById($id);
            }

            public function findAll(): array
            {
                return $this->real->findAll();
            }

            public function findAwaitingPasswordSetupInvitedBefore(DateTimeImmutable $threshold): array
            {
                return $this->real->findAwaitingPasswordSetupInvitedBefore($threshold);
            }

            public function countOtherSuperAdminsAbleToSignIn(CpgUser $except): int
            {
                return $this->real->countOtherSuperAdminsAbleToSignIn($except);
            }

            public function save(CpgUser $user): void
            {
                $this->real->save($user);
            }

            public function add(CpgUser $user): void
            {
                $this->real->add($user);
            }

            public function advanceInvitationGeneration(CpgUser $user, DateTimeImmutable $invitedAt): int
            {
                return $this->real->advanceInvitationGeneration($user, $invitedAt);
            }

            public function remove(CpgUser $user): void
            {
                $this->real->remove($user);
            }

            public function removePendingInvitation(CpgUser $user): void
            {
                $this->real->removePendingInvitation($user);
            }
        };
    }

    private function storeAccount(string $username, string $email): void
    {
        $user = new CpgUser($username, '');
        $user->setEmail($email);
        $user->markInvited(new DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function purge(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
