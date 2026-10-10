<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Application;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Entity\QualityTrait;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\OrderScopeLockTimeoutException;
use App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock;
use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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
 * @phpstan-type LocalizedContext array{
 *     create: Closure(Locale, ?Uuid): TranslatableContent,
 *     update: Closure(TranslatableContent, ?Uuid): mixed,
 *     reorder: Closure(list<string>): mixed,
 * }
 */
final class OrderScopeLockCoverageTest extends KernelTestCase
{
    use OpensProbeConnection;

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
        yield from self::localizedOperations(Contribution::class, static function (): array {
            $a = self::getContainer()->get(ContributionAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): Contribution => $a->create($l, 'T', 'P', 'R', 'https://example.com', 'S', 'B', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): Contribution => $a->update(self::as(Contribution::class, $e)->getId(), 'T', 'P', 'R', 'https://example.com', 'S', 'B', $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(Incident::class, static function (): array {
            $a = self::getContainer()->get(IncidentAdministratorInterface::class);
            $at = new DateTimeImmutable('2026-10-10');

            return [
                'create' => static fn (Locale $l, ?Uuid $g): Incident => $a->create($l, 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): Incident => $a->update(self::as(Incident::class, $e)->getId(), 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(QualityPrinciple::class, static function (): array {
            $a = self::getContainer()->get(QualityPrincipleAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityPrinciple => $a->create($l, 'T', 'D', 'shield', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): QualityPrinciple => $a->update(self::as(QualityPrinciple::class, $e)->getId(), 'T', 'D', 'shield', $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(QualityTrait::class, static function (): array {
            $a = self::getContainer()->get(QualityTraitAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityTrait => $a->create($l, 'L', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): QualityTrait => $a->update(self::as(QualityTrait::class, $e)->getId(), 'L', $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(AboutSiteCard::class, static function (): array {
            $a = self::getContainer()->get(AboutSiteCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutSiteCard => $a->create($l, 'T', 'D', null, $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AboutSiteCard => $a->update(self::as(AboutSiteCard::class, $e)->getId(), 'T', 'D', null, $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(AboutMeCard::class, static function (): array {
            $a = self::getContainer()->get(AboutMeCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutMeCard => $a->create($l, AboutMeCardCategory::HOBBY, 'T', 'D', null, $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AboutMeCard => $a->update(self::as(AboutMeCard::class, $e)->getId(), 'T', 'D', null, $g),
                'reorder' => static function (array $keys) use ($a): void {
                    /** @var list<string> $keys appelée par localizedOperations(), qui passe des clés de groupe */
                    $a->reorder(AboutMeCardCategory::HOBBY, $keys);
                },
            ];
        });
        yield from self::localizedOperations(AnonymousCvSection::class, static function (): array {
            $a = self::getContainer()->get(AnonymousCvSectionAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AnonymousCvSection => $a->create($l, 'T', 'S', 5, 'A', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AnonymousCvSection => $a->update(self::as(AnonymousCvSection::class, $e)->getId(), 'T', 'S', 5, 'A', $g),
                'reorder' => $a->reorder(...),
            ];
        });
        yield from self::localizedOperations(CaseStudy::class, static function (): array {
            $a = self::getContainer()->get(CaseStudyAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): CaseStudy => $a->create($l, 'T', 'P', 'S', 'C', 'M', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): CaseStudy => $a->update(self::as(CaseStudy::class, $e)->getId(), 'T', 'P', 'S', 'C', 'M', $g),
                'reorder' => $a->reorder(...),
            ];
        });

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
     * @template T of TranslatableContent
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function as(string $class, TranslatableContent $entry): TranslatableContent
    {
        \assert($entry instanceof $class);

        return $entry;
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
