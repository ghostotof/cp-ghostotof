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

    /**
     * Spec 0004 D1 : ce que le peuplement doit garantir et que rien d'autre ne
     * dit — l'entrée FR et l'entrée EN de même index sont **le même contenu**,
     * elles portent donc le même groupe de traduction. C'est ce lien que la
     * migration a dû déduire sur l'existant ; sur une base neuve, il naît ici.
     *
     * La seconde assertion garde l'autre bord : autant de groupes que
     * d'entrées par locale. Un partage trop large — un seul groupe pour toute
     * la table — satisferait la première et serait tout aussi faux.
     */
    public function testFrenchAndEnglishEntriesOfTheSameIndexShareATranslationGroup(): void
    {
        $this->commandTester()->execute([]);

        $french = $this->repository()->findByLocale(Locale::FR);
        $english = $this->repository()->findByLocale(Locale::EN);

        self::assertNotEmpty($french);
        self::assertSameSize($french, $english);

        $groups = [];

        foreach ($french as $index => $entry) {
            self::assertTrue(
                $entry->getTranslationGroup()->equals($english[$index]->getTranslationGroup()),
                sprintf('Les entrées de position %d ne partagent pas leur groupe de traduction.', $index),
            );

            $groups[] = $entry->getTranslationGroup()->toRfc4122();
        }

        self::assertSameSize($groups, array_unique($groups));
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
