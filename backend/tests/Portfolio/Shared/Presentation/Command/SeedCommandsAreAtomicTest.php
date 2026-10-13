<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Presentation\Command;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Entity\QualityTrait;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Tests\Portfolio\Shared\Support\InterruptsTheNthPersist;
use App\Tests\Portfolio\Shared\Support\PersistInterruptedException;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

/**
 * Un `app:*:seed` est atomique (issue #567) : interrompu à mi-parcours, il
 * n'a rien écrit — ni sa purge `--force`, ni ses créations.
 *
 * Avant : chaque commande enchaînait la purge (un `flush()` par ligne, hors
 * verrou et hors transaction) puis les créations (une transaction par
 * `Administrator::create()`). Une panne entre deux laissait une base
 * partielle : avec `--force`, le contenu d'avant détruit et quelques entrées
 * neuves ; sur une base vide, quelques lignes que le garde-fou tenait ensuite
 * pour « du contenu en place » — le Job de préprod relancé déclinait (code 0)
 * et passait vert sur une page à moitié vide (constat C2), et pour À propos et
 * Qualité, qui écrivent deux tables, la seconde restait vide pour de bon (C3).
 *
 * La panne est simulée au deuxième `persist()` : la première création a été
 * `flush()`ée quand elle tombe, ce qui est précisément ce qu'une commande non
 * atomique laissait en base.
 */
final class SeedCommandsAreAtomicTest extends KernelTestCase
{
    /**
     * Après une création écrite : la panne tombe au milieu du contenu, et
     * assez tôt pour les seeds à deux entrées (une par langue).
     */
    private const int INTERRUPTED_AT_PERSIST = 2;

    /** @var list<string> */
    private array $tables = [];

    private ?InterruptsTheNthPersist $interruption = null;

    protected function tearDown(): void
    {
        $this->stopInterrupting();
        $this->purge();
        parent::tearDown();
    }

    /**
     * Les sept commandes et les entités qu'elles écrivent — leurs tables sont
     * lues sur les métadonnées, pour ne pas les recopier ici.
     *
     * @return iterable<string, array{0: string, 1: non-empty-list<class-string>}>
     */
    public static function provideSeedCommands(): iterable
    {
        yield 'about' => ['app:about:seed', [AboutSiteCard::class, AboutMeCard::class, AboutSettings::class]];
        yield 'quality' => ['app:quality:seed', [QualityPrinciple::class, QualityTrait::class]];
        yield 'contributions' => ['app:contributions:seed', [Contribution::class]];
        yield 'incidents' => ['app:incidents:seed', [Incident::class]];
        yield 'watch' => ['app:watch:seed', [WatchedProduct::class]];
        yield 'case-studies' => ['app:case-studies:seed', [CaseStudy::class]];
        yield 'anonymous-cv' => ['app:anonymous-cv:seed', [AnonymousCvSection::class]];
    }

    /**
     * `--force` interrompu : le contenu d'avant est intact, mêmes lignes,
     * mêmes identifiants. Avant #567 la purge avait déjà validé ligne par
     * ligne, et une entrée neuve la remplaçait.
     *
     * @param non-empty-list<class-string> $entities
     */
    #[DataProvider('provideSeedCommands')]
    public function testAnInterruptedForceLeavesThePreviousContentIntact(string $command, array $entities): void
    {
        $this->bootFor($entities);
        self::assertSame(0, $this->commandTester($command)->execute([]));
        $before = $this->idsPerTable();
        self::assertNotSame([], array_filter($before), 'Le peuplement de départ n\'a rien écrit.');

        $this->interruptAtTheNthPersist();
        $failure = $this->failureOf(fn (): int => $this->commandTester($command)->execute(['--force' => true]));

        self::assertInstanceOf(PersistInterruptedException::class, $failure, \sprintf('La panne simulée n\'est pas remontée : %s.', get_debug_type($failure)));
        self::assertSame($before, $this->idsPerTable(), 'Un --force interrompu a laissé une base partielle.');
        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->isOpen(), 'La panne après la purge écrite a laissé l\'EntityManager ouvert sur des écritures annulées (#549).');
    }

    /**
     * Premier peuplement interrompu (constats C2, C3) : aucune ligne n'est
     * écrite, et la relance **sans** `--force` — celle du Job de préprod —
     * peuple tout. Avant #567, une ligne restait, le garde-fou la tenait
     * pour du contenu et la relance déclinait en code 0.
     *
     * @param non-empty-list<class-string> $entities
     */
    #[DataProvider('provideSeedCommands')]
    public function testAnInterruptedFirstSeedWritesNothingAndTheRelaunchFillsEverything(string $command, array $entities): void
    {
        $this->bootFor($entities);
        self::assertSame(0, $this->commandTester($command)->execute([]));
        $expected = $this->countsPerTable();
        $this->purge();

        $this->interruptAtTheNthPersist();
        $failure = $this->failureOf(fn (): int => $this->commandTester($command)->execute([]));

        self::assertInstanceOf(PersistInterruptedException::class, $failure, \sprintf('La panne simulée n\'est pas remontée : %s.', get_debug_type($failure)));
        self::assertSame(array_fill_keys($this->tables, 0), $this->countsPerTable(), 'Un premier peuplement interrompu a laissé des lignes.');
        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->isOpen(), 'La panne après la première création écrite a laissé l\'EntityManager ouvert sur des écritures annulées (#549).');

        $this->stopInterrupting();
        $this->resetTheEntityManager();
        $tester = $this->commandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertSame($expected, $this->countsPerTable(), 'La relance sans --force n\'a pas tout peuplé : '.$tester->getDisplay());
    }

    /**
     * @param non-empty-list<class-string> $entities
     */
    private function bootFor(array $entities): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tables = array_map(
            static fn (string $entity): string => $entityManager->getClassMetadata($entity)->getTableName(),
            $entities,
        );
        $this->purge();
    }

    private function interruptAtTheNthPersist(): void
    {
        $this->interruption = new InterruptsTheNthPersist(self::INTERRUPTED_AT_PERSIST);
        self::getContainer()->get(EntityManagerInterface::class)->getEventManager()
            ->addEventListener(Events::prePersist, $this->interruption);
    }

    private function stopInterrupting(): void
    {
        if (null === $this->interruption) {
            return;
        }

        self::getContainer()->get(EntityManagerInterface::class)->getEventManager()
            ->removeEventListener(Events::prePersist, $this->interruption);
        $this->interruption = null;
    }

    /**
     * Après une panne qui suit une écriture, le verrou a fermé l'EntityManager
     * (issue #549, vérifié par les tests) : la relance en demande un neuf,
     * comme un worker Messenger en reçoit un au message suivant.
     */
    private function resetTheEntityManager(): void
    {
        self::getContainer()->get(ManagerRegistry::class)->resetManager();
    }

    /**
     * @param Closure(): mixed $run
     */
    private function failureOf(Closure $run): ?Throwable
    {
        try {
            $run();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Lus en SQL : après la panne, l'EntityManager peut être fermé, et une
     * instance encore chargée ne dirait pas ce que la base porte.
     *
     * @return array<string, list<string>>
     */
    private function idsPerTable(): array
    {
        $ids = [];

        foreach ($this->tables as $table) {
            /** @var list<string> $column */
            $column = $this->connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table));
            $ids[$table] = $column;
        }

        return $ids;
    }

    /**
     * @return array<string, int>
     */
    private function countsPerTable(): array
    {
        return array_map(\count(...), $this->idsPerTable());
    }

    private function purge(): void
    {
        foreach ($this->tables as $table) {
            $this->connection()->executeStatement(\sprintf('DELETE FROM %s', $table));
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function commandTester(string $command): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);

        return new CommandTester(new Application(self::$kernel)->find($command));
    }
}
