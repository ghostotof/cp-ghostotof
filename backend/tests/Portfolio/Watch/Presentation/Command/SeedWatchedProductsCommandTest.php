<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Repository\WatchedProductRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Contrairement à app:watch:refresh, ce seed ne sort pas sur le réseau : il ne
 * fait qu'écrire le catalogue des produits suivis. Il peut donc être testé
 * comme les autres commandes du projet, à travers le conteneur réel.
 */
final class SeedWatchedProductsCommandTest extends KernelTestCase
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

    private function purge(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM watched_product');
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:watch:seed'));
    }

    private function repository(): WatchedProductRepositoryInterface
    {
        return self::getContainer()->get(WatchedProductRepositoryInterface::class);
    }

    public function testItSeedsTheWholeStackInDisplayOrder(): void
    {
        $exitCode = $this->commandTester()->execute([]);

        self::assertSame(0, $exitCode);

        $slugs = array_map(
            static fn (WatchedProduct $product): string => $product->getSlug(),
            $this->repository()->findAllOrdered(),
        );

        self::assertSame(
            ['php', 'symfony', 'postgresql', 'nodejs', 'vue', 'nginx', 'rabbitmq'],
            $slugs,
        );
    }

    /**
     * Décision D2 : ces deux entrées n'ont pas de version en base, elle est lue
     * dans le processus au moment du rafraîchissement. Un seed qui les
     * renseignerait « pour faire joli » réintroduirait la version périmée que
     * la décision cherche justement à éviter.
     */
    public function testRuntimeSourcedProductsCarryNoStoredVersion(): void
    {
        $this->commandTester()->execute([]);

        $php = $this->repository()->findOneBySlug('php');
        $symfony = $this->repository()->findOneBySlug('symfony');

        self::assertNotNull($php);
        self::assertNotNull($symfony);
        self::assertSame(VersionSource::RUNTIME_PHP, $php->getVersionSource());
        self::assertNull($php->getVersion());
        self::assertSame(VersionSource::RUNTIME_SYMFONY, $symfony->getVersionSource());
        self::assertNull($symfony->getVersion());
    }

    public function testManuallyVersionedProductsMatchTheDeployedStack(): void
    {
        $this->commandTester()->execute([]);

        $postgres = $this->repository()->findOneBySlug('postgresql');

        self::assertNotNull($postgres);
        self::assertSame(VersionSource::MANUAL, $postgres->getVersionSource());
        self::assertSame('18.4', $postgres->getVersion());
    }

    public function testItIsIdempotent(): void
    {
        $this->commandTester()->execute([]);
        $this->commandTester()->execute([]);

        self::assertCount(7, $this->repository()->findAllOrdered());
    }

    /**
     * Le contrat a changé le 2026-09-08 : un catalogue déjà en place n'est plus
     * écrasé. C'est ce qui permet de jouer ce seed à chaque déploiement de
     * préprod sans jamais rien détruire — et ce qui rend la production
     * intouchable, y compris sur une erreur de namespace.
     */
    public function testItLeavesAnExistingCatalogueAlone(): void
    {
        $this->repository()->save(new WatchedProduct('maison', 'Produit maison', VersionSource::MANUAL, '1.0', 99));

        $tester = $this->commandTester();
        $tester->execute([]);

        self::assertNotNull($this->repository()->findOneBySlug('maison'));
        self::assertCount(1, $this->repository()->findAllOrdered());
        self::assertStringContainsString('déjà en place', $tester->getDisplay());
    }

    /**
     * Le refus doit être un **succès**. Un code de sortie non nul ferait
     * échouer le Job de peuplement — donc le déploiement de préprod — à chaque
     * passage après le premier. « Il y a déjà du contenu » est la réponse
     * attendue dans la quasi-totalité des exécutions, pas une erreur.
     */
    public function testRefusingToOverwriteIsASuccess(): void
    {
        $this->repository()->save(new WatchedProduct('maison', 'Produit maison', VersionSource::MANUAL, '1.0', 99));

        $tester = $this->commandTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    /**
     * La réinitialisation délibérée reste possible — elle s'écrit.
     */
    public function testForceReplacesWhateverWasThereBefore(): void
    {
        $this->repository()->save(new WatchedProduct('obsolete', 'À supprimer', VersionSource::MANUAL, '1.0', 99));

        $this->commandTester()->execute(['--force' => true]);

        self::assertNull($this->repository()->findOneBySlug('obsolete'));
        self::assertCount(7, $this->repository()->findAllOrdered());
    }
}
