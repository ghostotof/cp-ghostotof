<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Presentation\Command;

use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AddExperienceTechnologyCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM experience_technology');
    }

    protected function tearDown(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    public function testAddsTechnologyWithGivenOptions(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--name' => 'PHP',
            '--years' => '13.5',
            '--icon' => 'php',
            '--related-technology' => 'HTML / CSS / JavaScript',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('PHP', $tester->getDisplay());

        $technology = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP');
        self::assertNotNull($technology);
        self::assertSame(13.5, $technology->getYears());
        self::assertSame('php', $technology->getIconKey());
        self::assertSame('HTML / CSS / JavaScript', $technology->getRelatedTechnologyName());
    }

    public function testAddsTechnologyWithoutOptionalFields(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'MySQL', '--years' => '13.5']);

        self::assertSame(0, $exitCode);

        $technology = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('MySQL');
        self::assertNotNull($technology);
        self::assertNull($technology->getIconKey());
        self::assertNull($technology->getRelatedTechnologyName());
    }

    public function testFailsWhenNameAlreadyUsed(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--name' => 'PHP', '--years' => '13.5']);

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testFailsOnNonNumericYears(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => 'not-a-number']);

        self::assertSame(1, $exitCode);
    }

    /**
     * Issue #372 : `is_numeric('1e999')` est vrai et `(float)` le rend en INF,
     * qui mettait la route publique en 500 une fois persisté ; `-5` passait
     * aussi, la commande ne passant pas par le Validator.
     *
     * @return iterable<string, array{string}>
     */
    public static function outOfRangeYears(): iterable
    {
        yield 'non fini' => ['1e999'];
        yield 'négatif' => ['-5'];
        yield 'au-delà de 100 ans' => ['100.5'];
    }

    #[DataProvider('outOfRangeYears')]
    public function testRefusesYearsOutOfRangeBeforeAnyWrite(string $years): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => $years]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('entre 0 et 100 ans', $this->normalizedDisplay($tester));
        self::assertNull(self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP'));
    }

    /**
     * L'option est validée avant tout appel au registrar : une durée invalide
     * est signalée comme telle même quand le nom est déjà pris, au lieu d'être
     * masquée par « existe déjà » après une requête en base inutile.
     */
    public function testAnInvalidYearsOptionIsReportedBeforeTheNameCollision(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--name' => 'PHP', '--years' => '13.5']);

        $exitCode = $tester->execute(['--name' => 'PHP', '--years' => '1e999']);

        self::assertSame(1, $exitCode);
        $display = $this->normalizedDisplay($tester);
        self::assertStringContainsString('entre 0 et 100 ans', $display);
        self::assertStringNotContainsString('existe déjà', $display);
    }

    /**
     * En interactif, la valeur hors bornes est refusée par le validateur de la
     * question, qui la redemande, plutôt qu'au moment d'écrire.
     */
    public function testInteractivePromptAsksAgainWhenYearsAreOutOfRange(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['1e999', '13.5']);

        $exitCode = $tester->execute(['--name' => 'PHP'], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('entre 0 et 100 ans', $this->normalizedDisplay($tester));
        $technology = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP');
        self::assertNotNull($technology);
        self::assertSame(13.5, $technology->getYears());
    }

    /**
     * Issue #383 : sans --name, `ask()` rend en non interactif la valeur par
     * défaut (null) sans passer par le validateur ; la commande doit refuser
     * en nommant l'option à passer, pas tomber sur un `assert()`.
     */
    public function testANonInteractiveRunWithoutNameFailsAndNamesTheOption(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--years' => '13.5'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--name', $tester->getDisplay());
    }

    public function testTheInteractivePromptAsksAgainWhenTheNameIsBlank(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['   ', 'PHP']);

        $exitCode = $tester->execute(['--years' => '13.5'], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('ne peut pas être vide', $this->normalizedDisplay($tester));
        self::assertNotNull(self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP'));
    }

    /**
     * Revue de #383 : sur une fin d'entrée, le QuestionHelper relance la
     * dernière erreur du validateur ; elle doit finir en message, pas quitter
     * la commande.
     */
    public function testABlankNameFollowedByTheEndOfInputFailsWithAMessage(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['   ']);

        $exitCode = $tester->execute(['--years' => '13.5'], ['interactive' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ne peut pas être vide', $this->normalizedDisplay($tester));
    }

    public function testANonInteractiveRunWithoutYearsFailsAndNamesTheOption(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--name' => 'PHP'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--years', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneByName('PHP'));
    }

    /** SymfonyStyle replie les blocs d'erreur à la largeur du terminal. */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:experience:add-technology'));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
