<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #372 : ExperienceYears garde toute écriture qui passe par l'entité,
 * mais Doctrine n'appelle pas le constructeur en hydratant, et une ligne
 * écrite en SQL (ou persistée avant la correction) n'est vue par aucune garde
 * PHP. Une seule ligne non encodable suffit à mettre la route publique en 500
 * pour tout le monde : la base refuse donc elle-même ce que le Value Object
 * refuse, avec les mêmes bornes.
 *
 * Chaque cas tourne dans une transaction annulée au tearDown : un INSERT
 * refusé interrompt la transaction PostgreSQL, rien ne peut fuir d'un cas à
 * l'autre.
 */
final class ExperienceTechnologyYearsConstraintTest extends KernelTestCase
{
    /** SQLSTATE de PostgreSQL pour une contrainte CHECK violée. */
    private const string CHECK_VIOLATION = '23514';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
        // Une base neuve n'est pas vide : Version20260906160000 insère des
        // technologies. Vidée dans la transaction, la table revient au tearDown.
        $this->connection->executeStatement('DELETE FROM experience_technology');
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * La borne haute est lue sur le Value Object : si l'une des deux bornes
     * bouge sans l'autre, ce test rougit.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedLiterals(): iterable
    {
        yield 'Infinity' => ["'Infinity'::float8"];
        yield '-Infinity' => ["'-Infinity'::float8"];
        yield 'NaN, que PostgreSQL range au-dessus de tout nombre' => ["'NaN'::float8"];
        yield 'négatif' => ['-0.5'];
        yield 'juste au-delà de la borne du Value Object' => [var_export(ExperienceYears::MAX + 0.5, true)];
    }

    #[DataProvider('refusedLiterals')]
    public function testTheDatabaseRefusesWhatTheValueObjectRefuses(string $yearsLiteral): void
    {
        try {
            $this->insertTechnologyWithYears($yearsLiteral);
            self::fail(sprintf('La base aurait dû refuser years = %s.', $yearsLiteral));
        } catch (DriverException $exception) {
            self::assertSame(self::CHECK_VIOLATION, $exception->getSQLState(), $exception->getMessage());
        }
    }

    public function testTheDatabaseAcceptsBothBoundsOfTheValueObject(): void
    {
        $this->insertTechnologyWithYears('0');
        $this->insertTechnologyWithYears(var_export(ExperienceYears::MAX, true));

        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM experience_technology'));
    }

    private function insertTechnologyWithYears(string $yearsLiteral): void
    {
        $this->connection->executeStatement(sprintf(
            "INSERT INTO experience_technology (id, name, years) VALUES (uuidv7(), 'T-' || gen_random_uuid(), %s)",
            $yearsLiteral,
        ));
    }
}
