<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) de
 * `findAwaitingPasswordSetupInvitedBefore()`, la méthode dont se sert
 * PendingInvitationPurger (issue #238) pour retrouver les comptes en attente
 * de définition de mot de passe invités depuis trop longtemps. Round de
 * correction (I2) : renommée depuis `findPendingActivationInvitedBefore()` et
 * son prédicat SQL complété d'un mot de passe vide — un compte invité dont le
 * mot de passe a été posé depuis le backoffice
 * (CpgUserAdministrator::changePassword(), sans jamais markActivated()) reste
 * `isPendingActivation()` vrai mais ne doit plus être purgeable.
 */
final class CpgUserRepositoryTest extends KernelTestCase
{
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

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    /**
     * Trois comptes : invité il y a 40 jours (encore en attente), invité il y
     * a 10 jours (encore en attente, trop récent) et invité il y a 40 jours
     * puis activé (donc plus en attente). Avec un seuil à -30 jours, seul le
     * premier doit ressortir.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeOnlyReturnsStalePendingAccounts(): void
    {
        $stale = new CpgUser('stale-invitee', '');
        $stale->setEmail('stale@example.com');
        $stale->markInvited(new \DateTimeImmutable('-40 days'));
        $this->em->persist($stale);

        $recent = new CpgUser('recent-invitee', '');
        $recent->setEmail('recent@example.com');
        $recent->markInvited(new \DateTimeImmutable('-10 days'));
        $this->em->persist($recent);

        $activated = new CpgUser('activated-invitee', '');
        $activated->setEmail('activated@example.com');
        $activated->markInvited(new \DateTimeImmutable('-40 days'));
        $activated->markActivated(new \DateTimeImmutable('-5 days'));
        $this->em->persist($activated);

        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findAwaitingPasswordSetupInvitedBefore(new \DateTimeImmutable('-30 days'));

        self::assertCount(1, $found);
        self::assertSame('stale-invitee', $found[0]->getUsername());
    }

    public function testFindAwaitingPasswordSetupInvitedBeforeReturnsEmptyListWhenNothingIsStale(): void
    {
        $recent = new CpgUser('recent-invitee', '');
        $recent->setEmail('recent@example.com');
        $recent->markInvited(new \DateTimeImmutable('-10 days'));
        $this->em->persist($recent);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new \DateTimeImmutable('-30 days')));
    }

    /**
     * I2 : un compte invité peut se voir poser un mot de passe depuis le
     * backoffice (CpgUserAdministrator::changePassword()) sans jamais être
     * marqué activé — il se connecte déjà et ne doit plus jamais être purgé,
     * même très ancien.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeExcludesAnAccountWhosePasswordWasSetFromTheBackoffice(): void
    {
        $passwordSet = new CpgUser('password-set-invitee', 'a-real-hash-set-from-the-backoffice');
        $passwordSet->setEmail('password-set@example.com');
        $passwordSet->markInvited(new \DateTimeImmutable('-40 days'));
        $this->em->persist($passwordSet);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new \DateTimeImmutable('-30 days')));
    }

    /**
     * I4 (défense en profondeur, symétrique au test unitaire du purgeur) : un
     * compte créé en ligne de commande (app:user:create) n'a jamais été
     * invité — invitedAt reste null pour toujours, quel que soit l'âge du
     * compte — et ne doit donc jamais ressortir, y compris avec un seuil très
     * large (dans le futur).
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeNeverReturnsAnAccountCreatedWithoutAnyInvitation(): void
    {
        $cliAccount = new CpgUser('cli-created-account', 'hashed-password');
        $this->em->persist($cliAccount);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new \DateTimeImmutable('+1 day')));
    }

    /**
     * L'ordre (`ORDER BY invitedAt ASC`) importe : le purgeur journalise et
     * agit dans un ordre stable plutôt que dans l'ordre arbitraire du moteur.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeOrdersFromOldestToMostRecent(): void
    {
        $newer = new CpgUser('older-of-the-stale-two', '');
        $newer->setEmail('newer-stale@example.com');
        $newer->markInvited(new \DateTimeImmutable('-35 days'));
        $this->em->persist($newer);

        $older = new CpgUser('newer-of-the-stale-two', '');
        $older->setEmail('older-stale@example.com');
        $older->markInvited(new \DateTimeImmutable('-50 days'));
        $this->em->persist($older);

        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findAwaitingPasswordSetupInvitedBefore(new \DateTimeImmutable('-30 days'));

        self::assertCount(2, $found);
        self::assertSame('newer-of-the-stale-two', $found[0]->getUsername());
        self::assertSame('older-of-the-stale-two', $found[1]->getUsername());
    }
}
