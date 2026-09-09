<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Presentation\Command;

use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Cette commande n'avait aucun test (issue #20). Le garde-fou posé sur les cinq
 * seeds y était donc seulement vérifié à la main — ce qui suffit une fois, pas
 * dans la durée : c'est ce câblage-là qui décide si la préprod se peuple toute
 * seule ou pas, et une régression ne se verrait qu'au moment où on compte
 * dessus.
 */
final class SeedContributionsContentCommandTest extends KernelTestCase
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

        self::assertCount(1, $repository->findByLocale(Locale::FR));
        self::assertCount(1, $repository->findByLocale(Locale::EN));

        $first = $repository->findByLocale(Locale::FR)[0];
        self::assertSame(
            'Retry de transport, re-prompt de validation : deux mécanismes, un seul mot',
            $first->getTitle(),
        );
        self::assertSame(0, $first->getPosition());
    }

    public function testItIsIdempotent(): void
    {
        $this->commandTester()->execute([]);
        $this->commandTester()->execute([]);

        self::assertCount(1, $this->repository()->findByLocale(Locale::FR));
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

        self::assertCount(0, $repository->findByLocale(Locale::EN));
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

        self::assertCount(1, $repository->findByLocale(Locale::EN));
    }

    private function repository(): ContributionRepositoryInterface
    {
        return self::getContainer()->get(ContributionRepositoryInterface::class);
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:contributions:seed'));
    }

    private function purge(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM contribution');
    }
}
