<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La garde qui précède toute instruction destructrice d'un test (#650, F-D de la
 * seconde relecture) : `TRUNCATE mcp_connection CASCADE`, `DELETE FROM cpg_user`
 * sans `WHERE` vident une table entière. Ils ne doivent jamais s'exécuter sur la
 * base de développement — qu'un `.env.test.local` mal copié, une connexion ouverte
 * à la main ou un `APP_ENV` oublié suffiraient à y mener.
 *
 * `doctrine.yaml` suffixe le nom de la base par `_test` en environnement `test`,
 * suivi du `TEST_TOKEN` de ParaTest quand il existe : la base courante doit finir
 * exactement par ce suffixe.
 */
final class TestDatabaseGuardTest extends KernelTestCase
{
    #[DataProvider('names')]
    public function testItRecognisesTheTestDatabaseWithOrWithoutAToken(string $name, string $token, bool $expected): void
    {
        self::assertSame($expected, TestDatabase::isTestDatabase($name, $token));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function names(): iterable
    {
        yield 'la base de test' => ['app_test', '', true];
        yield 'la base de dev' => ['app', '', false];
        yield 'un nom qui contient _test sans finir par lui' => ['app_test_backup', '', false];
        yield 'le suffixe seul' => ['_test', '', false];
        yield 'avec le jeton de ParaTest' => ['app_test2', '2', true];
        yield 'le jeton attendu mais absent du nom' => ['app_test', '2', false];
        yield 'un jeton sur une base sans jeton' => ['app_test2', '', false];
        yield 'la base de dev avec un jeton' => ['app', '2', false];
    }

    /** Une base de développement : l'instruction destructrice est refusée, et le message nomme la base. */
    public function testItRefusesADevelopmentDatabase(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('app');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('« app »');

        TestDatabase::assertSafeToWipe($connection);
    }

    /** La connexion du noyau de test, elle, est bien sur la base `_test` (suffixe de doctrine.yaml). */
    public function testTheKernelsConnectionIsOnTheTestDatabase(): void
    {
        self::bootKernel();

        TestDatabase::assertSafeToWipe(self::getContainer()->get(EntityManagerInterface::class)->getConnection());

        $this->addToAssertionCount(1);
    }
}
