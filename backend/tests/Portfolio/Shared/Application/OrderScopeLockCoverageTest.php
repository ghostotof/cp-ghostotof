<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Application;

use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockTimeoutException;
use App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Toute écriture qui place une entrée prend le verrou de son périmètre
 * (issue #389).
 *
 * ContributionOrderConcurrencyTest prouve, en concurrence réelle, que le
 * verrou défait la course. Ce test-ci prouve que **chaque** administrateur le
 * prend, à chaque opération qui calcule une position : `create()` (fin de
 * périmètre, ou position du groupe), `update()` (détacher, rattacher) et
 * `reorder()`. Une opération ajoutée ou réécrite sans `withLock()` le fait
 * passer au rouge.
 *
 * Méthode : une session témoin tient le verrou du périmètre, la connexion de
 * l'ORM reçoit un `lock_timeout` court, et l'opération doit **attendre** puis
 * échouer sur ce délai (`OrderScopeLockTimeoutException`), sans rien avoir écrit. Le périmètre est la classe de
 * l'entité qui porte les positions : c'est le contrat que l'administrateur
 * passe à `withLock()`.
 *
 * Ce que ce test ne voit pas : une lecture faite **avant** `withLock()`. Le
 * corps entier de chaque opération est enveloppé pour cette raison, et la
 * revue en reste juge.
 *
 * @phpstan-import-type LocalizedContext from ProvidesLocalizedAdministrators
 */
final class OrderScopeLockCoverageTest extends KernelTestCase
{
    use OpensProbeConnection;
    use ProvidesLocalizedAdministrators;

    /** Assez pour qu'une opération non verrouillée termine bien avant, assez peu pour un test rapide. */
    private const string LOCK_TIMEOUT = '300ms';

    private ?string $table = null;

    protected function tearDown(): void
    {
        if (null !== $this->table) {
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM '.$this->table);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: Closure(): (Closure(): mixed)}>
     */
    public static function provideOperationsThatPlaceAnEntry(): iterable
    {
        foreach (self::localizedAdministrators() as [$entityClass, $context]) {
            yield from self::localizedOperations($entityClass, $context);
        }

        // WatchedProduct n'est pas localisé : pas de groupe, et update() ne
        // touche pas à la position. Restent la fin de catalogue et le reorder.
        yield 'WatchedProduct create' => [WatchedProduct::class, static function (): Closure {
            $a = self::getContainer()->get(WatchedProductAdministratorInterface::class);
            $a->create('existant', 'Produit', VersionSource::MANUAL, '1.0');

            return static fn (): object => $a->create('nouveau', 'Produit', VersionSource::MANUAL, '1.0');
        }];
        yield 'WatchedProduct reorder' => [WatchedProduct::class, static function (): Closure {
            $a = self::getContainer()->get(WatchedProductAdministratorInterface::class);
            $first = $a->create('premier', 'Produit', VersionSource::MANUAL, '1.0');
            $second = $a->create('second', 'Produit', VersionSource::MANUAL, '1.0');

            return static fn () => $a->reorder([$second->orderingKey(), $first->orderingKey()]);
        }];
    }

    /**
     * @param class-string                  $entityClass l'entité qui porte les positions, donc le périmètre verrouillé
     * @param Closure(): (Closure(): mixed) $prepare     écrit les données de départ, rend l'opération à observer
     */
    #[DataProvider('provideOperationsThatPlaceAnEntry')]
    public function testTheOperationWaitsForTheScopeLock(string $entityClass, Closure $prepare): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $table = $entityManager->getClassMetadata($entityClass)->getTableName();
        $this->table = $table;
        $operation = $prepare();

        $connection = $entityManager->getConnection();
        $before = $this->snapshot($table);
        $probe = self::openProbeBeside($connection);
        $probe->executeQuery('SELECT pg_advisory_lock(?, hashtext(?))', [PostgresAdvisoryOrderScopeLock::ADVISORY_NAMESPACE, $entityClass]);
        $connection->executeStatement(\sprintf("SET lock_timeout = '%s'", self::LOCK_TIMEOUT));

        $waited = false;
        try {
            $operation();
        } catch (OrderScopeLockTimeoutException) {
            $waited = true;
        } finally {
            $connection->executeStatement('RESET lock_timeout');
            $probe->close();
        }

        self::assertTrue($waited, \sprintf("L'opération a écrit sans attendre le verrou du périmètre « %s » (#389).", $entityClass));
        self::assertSame($before, $this->snapshot($table), "L'opération a écrit avant d'avoir le verrou.");
    }

    /**
     * Les cinq opérations d'un contenu localisé qui calculent une position.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext        $context
     *
     * @return iterable<string, array{0: class-string, 1: Closure(): (Closure(): mixed)}>
     */
    private static function localizedOperations(string $entityClass, Closure $context): iterable
    {
        $label = substr($entityClass, (int) strrpos($entityClass, '\\') + 1);

        // Fin de périmètre : une entrée existe déjà, la nouvelle se range après.
        yield $label.' create' => [$entityClass, static function () use ($context): Closure {
            $operations = $context();
            $operations['create'](Locale::FR, null);

            return static fn (): TranslatableContent => $operations['create'](Locale::FR, null);
        }];
        // « Créer la version EN » : la position vient du groupe.
        yield $label.' create in a group' => [$entityClass, static function () use ($context): Closure {
            $operations = $context();
            $group = $operations['create'](Locale::FR, null)->getTranslationGroup();

            return static fn (): TranslatableContent => $operations['create'](Locale::EN, $group);
        }];
        // Détacher une entrée de sa traduction l'envoie en fin de périmètre.
        yield $label.' update that detaches' => [$entityClass, static function () use ($context): Closure {
            $operations = $context();
            $french = $operations['create'](Locale::FR, null);
            $operations['create'](Locale::EN, $french->getTranslationGroup());

            return static fn (): mixed => $operations['update']($french, null);
        }];
        // Rattacher une entrée seule à un groupe lui en fait hériter la position.
        yield $label.' update that reattaches' => [$entityClass, static function () use ($context): Closure {
            $operations = $context();
            $group = $operations['create'](Locale::FR, null)->getTranslationGroup();
            $english = $operations['create'](Locale::EN, null);

            return static fn (): mixed => $operations['update']($english, $group);
        }];
        yield $label.' reorder' => [$entityClass, static function () use ($context): Closure {
            $operations = $context();
            $first = $operations['create'](Locale::FR, null);
            $second = $operations['create'](Locale::FR, null);

            return static fn (): mixed => $operations['reorder']([$second->orderingKey(), $first->orderingKey()]);
        }];
    }

    /**
     * Toute la table, de quoi voir qu'une opération a écrit quoi que ce soit.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(string $table): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative(\sprintf('SELECT * FROM %s ORDER BY id', $table));
    }
}
