<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) de
 * `findPendingActivationInvitedBefore()`, la méthode dont se sert
 * PendingInvitationPurger (issue #238) pour retrouver les comptes en attente
 * d'activation invités depuis trop longtemps.
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
    public function testFindPendingActivationInvitedBeforeOnlyReturnsStalePendingAccounts(): void
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

        $found = $this->repository->findPendingActivationInvitedBefore(new \DateTimeImmutable('-30 days'));

        self::assertCount(1, $found);
        self::assertSame('stale-invitee', $found[0]->getUsername());
    }

    public function testFindPendingActivationInvitedBeforeReturnsEmptyListWhenNothingIsStale(): void
    {
        $recent = new CpgUser('recent-invitee', '');
        $recent->setEmail('recent@example.com');
        $recent->markInvited(new \DateTimeImmutable('-10 days'));
        $this->em->persist($recent);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findPendingActivationInvitedBefore(new \DateTimeImmutable('-30 days')));
    }

    /**
     * L'ordre (`ORDER BY invitedAt ASC`) importe : le purgeur journalise et
     * agit dans un ordre stable plutôt que dans l'ordre arbitraire du moteur.
     */
    public function testFindPendingActivationInvitedBeforeOrdersFromOldestToMostRecent(): void
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

        $found = $this->repository->findPendingActivationInvitedBefore(new \DateTimeImmutable('-30 days'));

        self::assertCount(2, $found);
        self::assertSame('newer-of-the-stale-two', $found[0]->getUsername());
        self::assertSame('older-of-the-stale-two', $found[1]->getUsername());
    }
}
