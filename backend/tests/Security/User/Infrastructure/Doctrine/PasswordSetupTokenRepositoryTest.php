<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) du dépôt des jetons de définition
 * de mot de passe : persistance, recherche par hash, purge par utilisateur, et
 * suppression en cascade quand le CpgUser porteur est supprimé.
 */
final class PasswordSetupTokenRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PasswordSetupTokenRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(PasswordSetupTokenRepositoryInterface::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    private function persistUser(string $username): CpgUser
    {
        $user = new CpgUser($username, 'hashed-password');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testSaveThenFindOneByTokenHash(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-token-jane');

        $this->repository->save(new PasswordSetupToken($user, $hash, new \DateTimeImmutable('+48 hours')));
        $this->em->clear();

        $found = $this->repository->findOneByTokenHash($hash);

        self::assertInstanceOf(PasswordSetupToken::class, $found);
        self::assertSame($hash, $found->getTokenHash());
        self::assertSame('jane', $found->getUser()->getUsername());
        self::assertNull($this->repository->findOneByTokenHash(hash('sha256', 'unknown')));
    }

    /**
     * Issue #356 (audit) : le compte arrive chargé avec le jeton, par
     * jointure. En proxy paresseux, le seul chemin « lien déjà consommé »
     * — qui journalise le compte — payait une requête de plus que le chemin
     * « expiré », et la différence de temps disait à l'appelant ce que le 410
     * fusionné doit taire.
     */
    public function testFindOneByTokenHashLoadsTheAccountWithTheToken(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $this->repository->save(new PasswordSetupToken($user, $hash, new \DateTimeImmutable('+48 hours')));
        $this->em->clear();

        $found = $this->repository->findOneByTokenHash($hash);

        self::assertInstanceOf(PasswordSetupToken::class, $found);
        self::assertFalse($this->em->getUnitOfWork()->isUninitializedObject($found->getUser()));
    }

    public function testFindOneByTokenHashIsNotConfusedByOtherUsersTokens(): void
    {
        $jane = $this->persistUser('jane');
        $john = $this->persistUser('john');
        $janeHash = hash('sha256', 'clear-jane');
        $johnHash = hash('sha256', 'clear-john');

        $this->repository->save(new PasswordSetupToken($jane, $janeHash, new \DateTimeImmutable('+48 hours')));
        $this->repository->save(new PasswordSetupToken($john, $johnHash, new \DateTimeImmutable('+48 hours')));
        $this->em->clear();

        self::assertSame('john', $this->repository->findOneByTokenHash($johnHash)?->getUser()->getUsername());
    }

    public function testDeleteForUserRemovesOnlyThatUsersTokens(): void
    {
        $jane = $this->persistUser('jane');
        $john = $this->persistUser('john');
        $janeHash = hash('sha256', 'clear-jane');
        $johnHash = hash('sha256', 'clear-john');

        $this->repository->save(new PasswordSetupToken($jane, $janeHash, new \DateTimeImmutable('+48 hours')));
        $this->repository->save(new PasswordSetupToken($john, $johnHash, new \DateTimeImmutable('+48 hours')));

        $this->repository->deleteForUser($jane);
        $this->em->clear();

        self::assertNull($this->repository->findOneByTokenHash($janeHash));
        self::assertNotNull($this->repository->findOneByTokenHash($johnHash));
    }

    public function testDeletingTheUserCascadesToItsTokens(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $this->repository->save(new PasswordSetupToken($user, $hash, new \DateTimeImmutable('+48 hours')));
        $this->em->clear();

        $managedUser = $this->em->getRepository(CpgUser::class)->findOneBy(['username' => 'jane']);
        self::assertInstanceOf(CpgUser::class, $managedUser);
        $this->em->remove($managedUser);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->repository->findOneByTokenHash($hash));
    }

    public function testMarkUsedIsPersistedThroughSave(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $token = new PasswordSetupToken($user, $hash, new \DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        $token->markUsed(new \DateTimeImmutable());
        $this->repository->save($token);
        $this->em->clear();

        $reloaded = $this->repository->findOneByTokenHash($hash);
        self::assertInstanceOf(PasswordSetupToken::class, $reloaded);
        self::assertFalse($reloaded->isUsable(new \DateTimeImmutable()));
    }

    /**
     * Issue #356 : la consommation est atomique — `UPDATE … WHERE used_at IS
     * NULL` —, une seule réclamation l'emporte.
     */
    public function testClaimConsumesAnUnusedTokenOnlyOnce(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $token = new PasswordSetupToken($user, $hash, new \DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        self::assertTrue($this->repository->claim($token, new \DateTimeImmutable('2026-10-05 12:00:00')));
        self::assertFalse($this->repository->claim($token, new \DateTimeImmutable('2026-10-05 12:00:01')));

        $this->em->clear();
        $reloaded = $this->repository->findOneByTokenHash($hash);
        self::assertSame('2026-10-05 12:00:00', $reloaded?->getUsedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * Le cas que la vérification en mémoire (`isUsable()`) laissait passer :
     * deux requêtes lisent le jeton inutilisé, l'autre le consomme entre-temps.
     * L'entité de celle-ci le croit encore libre ; la base, elle, tranche.
     */
    public function testClaimLosesTheRaceAgainstAConcurrentConsumption(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new \DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        $this->em->getConnection()->executeStatement(
            'UPDATE password_setup_token SET used_at = :usedAt WHERE id = :id',
            ['usedAt' => '2026-10-05 11:59:59', 'id' => $token->getId()->toRfc4122()],
        );

        self::assertNull($token->getUsedAt(), 'Prémisse : l\'entité en mémoire ignore la consommation concurrente.');
        self::assertFalse($this->repository->claim($token, new \DateTimeImmutable('2026-10-05 12:00:00')));
    }

    public function testIsUsableRejectsExpiredTokens(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $token = new PasswordSetupToken($user, hash('sha256', 'x'), new \DateTimeImmutable('2026-09-03 12:00:00'));

        self::assertTrue($token->isUsable(new \DateTimeImmutable('2026-09-03 11:59:59')));
        self::assertFalse($token->isUsable(new \DateTimeImmutable('2026-09-03 12:00:01')));
    }
}
