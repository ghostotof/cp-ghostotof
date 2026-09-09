<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Presentation\Command;

use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Cette commande n'avait aucun test (issue #20) — voir le pendant côté
 * Contribution pour le raisonnement.
 */
final class SeedIncidentsContentCommandTest extends KernelTestCase
{
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

    public function testItSeedsBothLocalesOnAnEmptyDatabase(): void
    {
        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute([]));

        $repository = $this->repository();

        self::assertCount(4, $repository->findByLocale(Locale::FR));
        self::assertCount(4, $repository->findByLocale(Locale::EN));

        $first = $repository->findByLocale(Locale::FR)[0];
        self::assertSame('Un correctif déployé, vérifié, et pourtant sans effet', $first->getTitle());
        self::assertSame(0, $first->getPosition());
    }

    /**
     * L'invariant est la ligne éditoriale de la page rendue structurelle : une
     * entrée qu'on ne peut pas enregistrer sans énoncer la règle qu'on en a
     * tirée ne peut pas dériver en confession. Le contenu de référence doit
     * donc, lui aussi, en porter un partout.
     */
    public function testEveryReferenceIncidentStatesItsInvariant(): void
    {
        $this->commandTester()->execute([]);

        foreach (Locale::cases() as $locale) {
            foreach ($this->repository()->findByLocale($locale) as $incident) {
                self::assertNotSame('', trim($incident->getInvariant()));
            }
        }
    }

    public function testItIsIdempotent(): void
    {
        $this->commandTester()->execute([]);
        $this->commandTester()->execute([]);

        self::assertCount(4, $this->repository()->findByLocale(Locale::FR));
    }

    /**
     * Le contrat depuis la PR #16 : une base déjà peuplée est laissée intacte.
     * Le test l'établit en amputant le contenu — si la commande recréait, la
     * suppression serait réparée, et elle ne doit pas l'être.
     */
    public function testItLeavesExistingContentAlone(): void
    {
        $this->commandTester()->execute([]);

        $repository = $this->repository();
        $repository->remove($repository->findByLocale(Locale::EN)[0]);

        $tester = $this->commandTester();
        $tester->execute([]);

        self::assertCount(3, $repository->findByLocale(Locale::EN));
        self::assertStringContainsString('déjà en place', $tester->getDisplay());
    }

    /**
     * Le refus doit être un **succès**. Un code non nul ferait échouer le Job
     * de peuplement — donc le déploiement de préprod — à chaque passage après
     * le premier.
     */
    public function testRefusingToOverwriteIsASuccess(): void
    {
        $this->commandTester()->execute([]);

        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute([]));
    }

    public function testForceRebuildsFromReferenceContent(): void
    {
        $this->commandTester()->execute([]);

        $repository = $this->repository();
        $repository->remove($repository->findByLocale(Locale::EN)[0]);

        $this->commandTester()->execute(['--force' => true]);

        self::assertCount(4, $repository->findByLocale(Locale::EN));
    }

    private function repository(): IncidentRepositoryInterface
    {
        return self::getContainer()->get(IncidentRepositoryInterface::class);
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:incidents:seed'));
    }

    private function purge(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM incident');
    }
}
