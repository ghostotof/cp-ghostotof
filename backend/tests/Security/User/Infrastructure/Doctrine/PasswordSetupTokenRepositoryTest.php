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

    public function testIsUsableRejectsExpiredTokens(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $token = new PasswordSetupToken($user, hash('sha256', 'x'), new \DateTimeImmutable('2026-09-03 12:00:00'));

        self::assertTrue($token->isUsable(new \DateTimeImmutable('2026-09-03 11:59:59')));
        self::assertFalse($token->isUsable(new \DateTimeImmutable('2026-09-03 12:00:01')));
    }
}
