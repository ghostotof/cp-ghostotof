<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Presentation\Command;

use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * ADR 0003 D5, Task 10 (#55). Même garde-fou que les autres commandes
 * app:*:seed (GuardsExistingContent), en miroir de
 * SeedCaseStudyContentCommandTest. Le contenu posé est un placeholder
 * explicite (titre préfixé « [Exemple] »).
 */
final class SeedAnonymousCvContentCommandTest extends KernelTestCase
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
        self::assertStringStartsWith('[Exemple]', $first->getTitle());
        self::assertSame(0, $first->getPosition());
    }

    public function testItIsIdempotent(): void
    {
        $this->commandTester()->execute([]);
        $this->commandTester()->execute([]);

        self::assertCount(1, $this->repository()->findByLocale(Locale::FR));
    }

    /**
     * Le contrat GuardsExistingContent : une base déjà peuplée est laissée
     * intacte. Le test l'établit en amputant le contenu — si la commande
     * recréait, la suppression serait réparée, et elle ne doit pas l'être.
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
     * Le refus doit être un succès (code 0) : un code non nul ferait échouer
     * le Job de peuplement — donc le déploiement de préprod — à chaque
     * passage après le premier.
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

    private function repository(): AnonymousCvSectionRepositoryInterface
    {
        return self::getContainer()->get(AnonymousCvSectionRepositoryInterface::class);
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:anonymous-cv:seed'));
    }

    private function purge(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM anonymous_cv_section');
    }
}
