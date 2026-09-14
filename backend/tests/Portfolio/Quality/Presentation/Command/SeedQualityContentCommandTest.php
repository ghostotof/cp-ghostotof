<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Presentation\Command;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class SeedQualityContentCommandTest extends KernelTestCase
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

    public function testSeedsPrinciplesAndTraitsForBothLocales(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = self::getContainer()->get(QualityTraitRepositoryInterface::class);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
        self::assertCount(3, $principleRepository->findByLocale(Locale::EN));
        self::assertCount(7, $traitRepository->findByLocale(Locale::FR));
        self::assertCount(7, $traitRepository->findByLocale(Locale::EN));

        $firstFrPrinciple = $principleRepository->findByLocale(Locale::FR)[0];
        self::assertSame('DDD', $firstFrPrinciple->getTitle());
        self::assertSame(0, $firstFrPrinciple->getPosition());
    }

    public function testIsIdempotent(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);
        $tester->execute([]);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = self::getContainer()->get(QualityTraitRepositoryInterface::class);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
        self::assertCount(7, $traitRepository->findByLocale(Locale::FR));
    }

    /**
     * Le comptage traverse ici **deux dépôts et deux locales** — la forme la
     * plus riche des cinq commandes de peuplement. Un seul principe déjà en
     * base suffit à protéger l'ensemble : c'est ce qui permet de jouer ce seed
     * à chaque déploiement de préprod sans jamais rien écraser.
     */
    public function testItLeavesExistingContentAlone(): void
    {
        $this->commandTester()->execute([]);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $principleRepository->remove($principleRepository->findByLocale(Locale::FR)[0]);

        $tester = $this->commandTester();
        $tester->execute([]);

        // Toujours amputé : la commande a bien renoncé plutôt que de recréer.
        self::assertCount(2, $principleRepository->findByLocale(Locale::FR));
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('déjà en place', $tester->getDisplay());
    }

    public function testForceRebuildsFromReferenceContent(): void
    {
        $this->commandTester()->execute([]);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $principleRepository->remove($principleRepository->findByLocale(Locale::FR)[0]);

        $this->commandTester()->execute(['--force' => true]);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
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

        $repositories = [
            'principes' => self::getContainer()->get(QualityPrincipleRepositoryInterface::class),
            'traits' => self::getContainer()->get(QualityTraitRepositoryInterface::class),
        ];

        foreach ($repositories as $label => $repository) {
            $french = $repository->findByLocale(Locale::FR);
            $english = $repository->findByLocale(Locale::EN);

            self::assertNotEmpty($french);
            self::assertSameSize($french, $english);

            $groups = [];

            foreach ($french as $index => $entry) {
                self::assertTrue(
                    $entry->getTranslationGroup()->equals($english[$index]->getTranslationGroup()),
                    sprintf('[%s] Les entrées de position %d ne partagent pas leur groupe.', $label, $index),
                );

                $groups[] = $entry->getTranslationGroup()->toRfc4122();
            }

            self::assertSameSize($groups, array_unique($groups));
        }
    }

    /**
     * Les deux périmètres de cette commande numérotent chacun depuis zéro :
     * sans le `$scope` de TranslationGroupIndex, le principe 0 et le trait 0
     * partageraient un groupe et se prétendraient traductions l'un de l'autre.
     */
    public function testPrinciplesAndTraitsNeverShareAGroup(): void
    {
        $this->commandTester()->execute([]);

        $principleGroups = array_map(
            static fn (QualityPrinciple $principle): string => $principle->getTranslationGroup()->toRfc4122(),
            self::getContainer()->get(QualityPrincipleRepositoryInterface::class)->findByLocale(Locale::FR),
        );
        $traitGroups = array_map(
            static fn (QualityTraitEntity $trait): string => $trait->getTranslationGroup()->toRfc4122(),
            self::getContainer()->get(QualityTraitRepositoryInterface::class)->findByLocale(Locale::FR),
        );

        self::assertSame([], array_intersect($principleGroups, $traitGroups));
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:quality:seed'));
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM quality_principle');
        $connection->executeStatement('DELETE FROM quality_trait');
    }
}
