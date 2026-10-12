<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Application;

use App\Portfolio\Experience\Application\ExperienceTechnologyAdministratorInterface;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyLockTimeoutException;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Les écritures du backoffice sur une technologie décident sur ce que la base
 * porte, pas sur ce que le provider a chargé (issue #545).
 *
 * Le provider d'un `PUT` ou d'un `DELETE` charge la technologie avant le
 * processor, et `find()` la rend ensuite depuis la carte d'identité. Une
 * suppression validée entre-temps laissait l'`UPDATE` ou le `DELETE` ne
 * toucher aucune ligne, sans rien lever : Doctrine ne compte les lignes
 * touchées que d'une entité versionnée. Le `PUT` répondait 200 en décrivant
 * une technologie disparue, et le perdant de deux `DELETE` 204.
 *
 * Chaque cas charge la technologie comme le provider, puis la supprime en SQL
 * avant l'appel : c'est la fenêtre entière, du provider au `flush()`.
 *
 * La garde de couverture : une sonde tient la ligne, et chaque écriture doit
 * attendre son verrou **avant** de rien lire ni écrire — elle échoue alors sur
 * le délai du verrou, sous son nom. Sans le verrou, l'`UPDATE` ou le `DELETE`
 * du `flush()` attendait lui aussi la ligne, mais échouait en `DriverException`
 * anonyme, après avoir décidé sur l'instance du provider.
 */
final class ExperienceTechnologyWritesUnderRowLockTest extends KernelTestCase
{
    use OpensProbeConnection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('RESET lock_timeout');
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    public function testAnUpdateOfATechnologyDeletedSinceItsLoadingIsANotFound(): void
    {
        $id = $this->loadedAsTheProviderDoes();
        $this->deleteInSql($id);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $this->administrator()->update($id, TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(4.0), null, null);
    }

    public function testTheSecondDeletionOfATechnologyIsANotFound(): void
    {
        $id = $this->loadedAsTheProviderDoes();
        $this->deleteInSql($id);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $this->administrator()->delete($id);
    }

    /**
     * Cas nominal sous le vrai verrou : l'écriture est validée, et la ligne
     * rendue au COMMIT.
     */
    public function testAnUpdateUnderTheLockIsCommittedAndReleasesTheRow(): void
    {
        $id = $this->loadedAsTheProviderDoes();

        $updated = $this->administrator()->update($id, TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(4.0), 'symfony', null);

        self::assertSame('Symfony', $updated->getName());
        self::assertSame('Symfony', $this->em->getConnection()->fetchOne('SELECT name FROM experience_technology WHERE id = :id', ['id' => $id->toRfc4122()]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertTrue($this->probeCanLockTheRow($id), 'La ligne est restée verrouillée après le COMMIT.');
    }

    public function testADeletionUnderTheLockRemovesTheRow(): void
    {
        $id = $this->loadedAsTheProviderDoes();

        $this->administrator()->delete($id);

        self::assertFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM experience_technology WHERE id = :id', ['id' => $id->toRfc4122()]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * @return iterable<string, array{Closure(ExperienceTechnologyAdministratorInterface, Uuid): mixed}>
     */
    public static function writes(): iterable
    {
        yield 'update' => [static fn (ExperienceTechnologyAdministratorInterface $administrator, Uuid $id): mixed => $administrator->update($id, TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(4.0), null, null)];
        yield 'delete' => [static function (ExperienceTechnologyAdministratorInterface $administrator, Uuid $id): void {
            $administrator->delete($id);
        }];
    }

    /**
     * @param Closure(ExperienceTechnologyAdministratorInterface, Uuid): mixed $write
     */
    #[DataProvider('writes')]
    public function testEveryWriteWaitsForTheRowLock(Closure $write): void
    {
        $id = $this->loadedAsTheProviderDoes();
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT 1 FROM experience_technology WHERE id = :id FOR UPDATE', ['id' => $id->toRfc4122()]);
        // Délai propre à la session : le squelette le garde tel quel.
        $this->em->getConnection()->executeStatement("SET lock_timeout = '50ms'");
        $caught = null;

        try {
            $write($this->administrator(), $id);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $holder->rollBack();
            $holder->close();
        }

        self::assertInstanceOf(ExperienceTechnologyLockTimeoutException::class, $caught, \sprintf("L'écriture n'a pas attendu le verrou de la ligne : %s.", get_debug_type($caught)));
    }

    /**
     * Enregistre une technologie, vide l'EntityManager, puis la recharge par
     * le dépôt : l'instance gérée est celle que le provider aurait laissée.
     */
    private function loadedAsTheProviderDoes(): Uuid
    {
        $technology = new ExperienceTechnology(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(3.0));
        $this->em->persist($technology);
        $this->em->flush();
        $this->em->clear();

        $loaded = self::getContainer()->get(ExperienceTechnologyRepositoryInterface::class)->findOneById($technology->getId());
        self::assertNotNull($loaded);

        return $loaded->getId();
    }

    /**
     * Tente la ligne depuis une autre session sans jamais l'attendre
     * (`SKIP LOCKED` : une ligne tenue n'est pas rendue).
     */
    private function probeCanLockTheRow(Uuid $id): bool
    {
        $probe = self::openProbeBeside($this->em->getConnection());

        try {
            return 1 === $probe->fetchOne('SELECT 1 FROM experience_technology WHERE id = :id FOR UPDATE SKIP LOCKED', ['id' => $id->toRfc4122()]);
        } finally {
            $probe->close();
        }
    }

    private function deleteInSql(Uuid $id): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology WHERE id = :id', ['id' => $id->toRfc4122()]);
    }

    private function administrator(): ExperienceTechnologyAdministratorInterface
    {
        return self::getContainer()->get(ExperienceTechnologyAdministratorInterface::class);
    }
}
