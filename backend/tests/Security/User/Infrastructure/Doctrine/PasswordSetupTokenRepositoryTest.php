<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\ValueObject\TokenClaim;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use DateTimeImmutable;
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

        $this->repository->save(new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours')));
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
     * « expiré », et la différence de temps disait à l'appelant ce que le
     * refus fusionné doit taire.
     */
    public function testFindOneByTokenHashLoadsTheAccountWithTheToken(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $this->repository->save(new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours')));
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

        $this->repository->save(new PasswordSetupToken($jane, $janeHash, new DateTimeImmutable('+48 hours')));
        $this->repository->save(new PasswordSetupToken($john, $johnHash, new DateTimeImmutable('+48 hours')));
        $this->em->clear();

        self::assertSame('john', $this->repository->findOneByTokenHash($johnHash)?->getUser()->getUsername());
    }

    public function testDeleteForUserRemovesOnlyThatUsersTokens(): void
    {
        $jane = $this->persistUser('jane');
        $john = $this->persistUser('john');
        $janeHash = hash('sha256', 'clear-jane');
        $johnHash = hash('sha256', 'clear-john');

        $this->repository->save(new PasswordSetupToken($jane, $janeHash, new DateTimeImmutable('+48 hours')));
        $this->repository->save(new PasswordSetupToken($john, $johnHash, new DateTimeImmutable('+48 hours')));

        $this->repository->deleteForUser($jane);
        $this->em->clear();

        self::assertNull($this->repository->findOneByTokenHash($janeHash));
        self::assertNotNull($this->repository->findOneByTokenHash($johnHash));
    }

    public function testDeletingTheUserCascadesToItsTokens(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $this->repository->save(new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours')));
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
        $token = new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        $token->markUsed(new DateTimeImmutable());
        $this->repository->save($token);
        $this->em->clear();

        $reloaded = $this->repository->findOneByTokenHash($hash);
        self::assertInstanceOf(PasswordSetupToken::class, $reloaded);
        self::assertFalse($reloaded->isUsable(new DateTimeImmutable()));
    }

    /**
     * Issue #356 : la consommation est atomique — `UPDATE … WHERE used_at IS
     * NULL` —, une seule réclamation l'emporte.
     */
    public function testClaimConsumesAnUnusedTokenOnlyOnce(): void
    {
        $user = $this->persistUser('jane');
        $hash = hash('sha256', 'clear-jane');
        $token = new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        self::assertSame(TokenClaim::Claimed, $this->repository->claim($token, new DateTimeImmutable('2026-10-05 12:00:00')));
        self::assertSame(TokenClaim::AlreadyUsed, $this->repository->claim($token, new DateTimeImmutable('2026-10-05 12:00:01')));

        $this->em->clear();
        $reloaded = $this->repository->findOneByTokenHash($hash);
        // L'instant, pas l'heure murale : la colonne est avec fuseau (#434) et
        // PostgreSQL le rend dans le fuseau de sa session.
        self::assertSame((new DateTimeImmutable('2026-10-05 12:00:00'))->getTimestamp(), $reloaded?->getUsedAt()?->getTimestamp());
    }

    /**
     * Issue #447 : l'expiration est atomique comme la consommation. Un jeton
     * expiré à l'instant de la réclamation n'est pas consommé — à l'échéance
     * pile non plus, comme `isUsable()`.
     */
    public function testClaimRefusesATokenExpiredAtTheClaimInstant(): void
    {
        $user = $this->persistUser('jane');
        $expiresAt = new DateTimeImmutable('2026-10-05 12:00:00');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), $expiresAt);
        $this->repository->save($token);

        self::assertSame(TokenClaim::Expired, $this->repository->claim($token, $expiresAt));

        self::assertNull($this->em->getConnection()->fetchOne(
            'SELECT used_at FROM password_setup_token WHERE id = :id',
            ['id' => $token->getId()->toRfc4122()],
        ), 'Un jeton expiré a été consommé.');
    }

    /**
     * Issue #458 : à l'activation, les jetons encore utilisables du compte
     * disparaissent ; le jeton consommé reste, pour qu'un rejeu soit reconnu.
     */
    public function testDeleteUnusedForUserKeepsOnlyTheConsumedToken(): void
    {
        $user = $this->persistUser('jane');
        $consumed = new PasswordSetupToken($user, hash('sha256', 'consumed-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($consumed);
        $this->repository->save(new PasswordSetupToken($user, hash('sha256', 'pending-jane'), new DateTimeImmutable('+48 hours')));
        self::assertSame(TokenClaim::Claimed, $this->repository->claim($consumed, new DateTimeImmutable()));

        $this->repository->deleteUnusedForUser($user);

        self::assertSame([hash('sha256', 'consumed-jane')], $this->em->getConnection()->fetchFirstColumn(
            'SELECT token_hash FROM password_setup_token WHERE user_id = :user',
            ['user' => $user->getId()->toRfc4122()],
        ));
    }

    /**
     * Seconde passe d'audit de #458 : un jeton supprimé entre-temps (une
     * relance l'a remplacé pendant le hachage du mot de passe) n'est pas un
     * jeton déjà utilisé — le journal ne doit pas y voir un rejeu.
     */
    public function testClaimTellsAGoneTokenFromAUsedOne(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token WHERE id = :id', ['id' => $token->getId()->toRfc4122()]);

        self::assertSame(TokenClaim::Gone, $this->repository->claim($token, new DateTimeImmutable()));
    }

    /**
     * Le retrait d'un jeton dont l'e-mail n'est pas parti (#425) n'emporte
     * jamais un jeton déjà consommé : l'e-mail a pu partir malgré l'erreur, et
     * la personne activer son compte entre-temps (seconde passe d'audit de
     * #458). Le rejeu du lien doit rester journalisé « replayed ».
     */
    public function testRemoveSparesAConsumedToken(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);
        self::assertSame(TokenClaim::Claimed, $this->repository->claim($token, new DateTimeImmutable()));

        $this->repository->remove($token);

        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM password_setup_token WHERE id = :id', ['id' => $token->getId()->toRfc4122()]));
    }

    /**
     * Issue #569 : la suppression DQL laissait le jeton **géré** alors que sa
     * ligne n'existait plus ; une modification puis un `save()` émettaient un
     * `UPDATE` sur 0 ligne, perdu en silence. Le jeton supprimé est désormais
     * détaché et retenu par VanishedEntities : sa re-sauvegarde est refusée
     * sous la cause « supprimée par cet EntityManager ».
     */
    public function testRemoveForgetsTheDeletedTokenAndRefusesItsResave(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);
        $id = $token->getId()->toRfc4122();

        $this->repository->remove($token);
        $stillManaged = $this->em->contains($token);
        $token->markUsed(new DateTimeImmutable());
        $refusal = null;
        try {
            $this->repository->save($token);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM password_setup_token'), 'Le jeton supprimé, re-sauvé, a été réinséré.');
        self::assertFalse($stillManaged, "Le jeton supprimé en DQL est resté géré par l'EntityManager (#569).");
        self::assertNotNull($refusal, 'La re-sauvegarde du jeton supprimé en DQL est passée en silence, UPDATE sur 0 ligne (#569).');
        self::assertSame(VanishedEntitySavedAgainException::removedByThisManager(PasswordSetupToken::class, $id)->getMessage(), $refusal->getMessage());
        self::assertTrue($this->em->contains($user), 'Détacher le jeton a détaché son compte.');
    }

    /**
     * Le jeton consommé n'est pas supprimé (0 ligne touchée) : il reste géré,
     * et l'écrire reste possible. Seul un DELETE qui a touché sa ligne
     * détache l'instance.
     */
    public function testRemoveLeavesASparedTokenManaged(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);
        $usedAt = new DateTimeImmutable('2026-10-13T10:00:00+02:00');
        self::assertSame(TokenClaim::Claimed, $this->repository->claim($token, $usedAt));

        $this->repository->remove($token);
        $stillManaged = $this->em->contains($token);
        // Une autre valeur que celle de claim() : relue en base, elle prouve
        // que l'écriture a eu lieu.
        $rewrittenAt = new DateTimeImmutable('2026-10-13T10:05:00+02:00');
        $token->markUsed($rewrittenAt);
        $this->repository->save($token);

        self::assertTrue($stillManaged, 'Le jeton épargné par la suppression a été détaché.');
        $stored = $this->em->getConnection()->fetchOne('SELECT used_at FROM password_setup_token');
        self::assertIsString($stored);
        self::assertSame($rewrittenAt->getTimestamp(), new DateTimeImmutable($stored)->getTimestamp(), "L'écriture du jeton épargné n'a pas atteint la base.");
    }

    /**
     * Le cas que la vérification en mémoire (`isUsable()`) laissait passer :
     * deux requêtes lisent le jeton inutilisé, l'autre le consomme entre-temps.
     * L'entité de celle-ci le croit encore libre ; la base, elle, tranche.
     */
    public function testClaimLosesTheRaceAgainstAConcurrentConsumption(): void
    {
        $user = $this->persistUser('jane');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear-jane'), new DateTimeImmutable('+48 hours'));
        $this->repository->save($token);

        $this->em->getConnection()->executeStatement(
            'UPDATE password_setup_token SET used_at = :usedAt WHERE id = :id',
            ['usedAt' => '2026-10-05 11:59:59', 'id' => $token->getId()->toRfc4122()],
        );

        self::assertNull($token->getUsedAt(), 'Prémisse : l\'entité en mémoire ignore la consommation concurrente.');
        self::assertSame(TokenClaim::AlreadyUsed, $this->repository->claim($token, new DateTimeImmutable('2026-10-05 12:00:00')));
    }

    public function testIsUsableRejectsExpiredTokens(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $token = new PasswordSetupToken($user, hash('sha256', 'x'), new DateTimeImmutable('2026-09-03 12:00:00'));

        self::assertTrue($token->isUsable(new DateTimeImmutable('2026-09-03 11:59:59')));
        self::assertFalse($token->isUsable(new DateTimeImmutable('2026-09-03 12:00:01')));
    }
}
