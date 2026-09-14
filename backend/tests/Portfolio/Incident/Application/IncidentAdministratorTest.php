<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Application;

use App\Portfolio\Incident\Application\IncidentAdministrator;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Portfolio\Incident\Support\InMemoryIncidentRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 B3/B4 : `reorder()` sur un `Administrator` représentatif des huit
 * contextes localisés (périmètre = la table entière, groupé par groupe de
 * traduction) — `AboutMeCardAdministratorTest` couvre le périmètre par
 * catégorie, `WatchedProductAdministratorTest` le périmètre par id.
 */
final class IncidentAdministratorTest extends TestCase
{
    private function incident(Locale $locale, int $position, string $title): Incident
    {
        return new Incident(
            $locale,
            $title,
            '1.0',
            new \DateTimeImmutable('2026-01-01'),
            'Impact.',
            'Cause.',
            'Résolution.',
            'Invariant.',
            $position,
        );
    }

    /**
     * Le périmètre vient de `findAll()` (toutes langues confondues) et la
     * persistance passe par l'unique appel à `saveAll()` porté par
     * `IncidentRepositoryInterface::saveAll()` — jamais par `save()`, qui
     * ouvrirait une transaction par entité.
     */
    public function testReorderLoadsTheWholeScopeAndSavesItInOneCall(): void
    {
        $first = $this->incident(Locale::FR, 0, 'Premier incident');
        $second = $this->incident(Locale::FR, 1, 'Second incident');
        $repository = new InMemoryIncidentRepository([$first, $second]);

        $administrator = new IncidentAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $administrator->reorder([
            $second->getTranslationGroup()->toRfc4122(),
            $first->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertSame(1, $first->getPosition());
        self::assertSame(0, $second->getPosition());
        self::assertSame(0, $repository->saveCallCount);
        self::assertCount(1, $repository->saveAllCalls);
        self::assertSame([$first, $second], $repository->saveAllCalls[0]);
    }

    /**
     * Un groupe de traduction à deux locales (FR + EN du même contenu) reçoit
     * la même position — l'exigence « un déplacement suit le contenu quelle
     * que soit la langue » (spec 0004 D5), déjà pinnée sur `OrderAssigner`
     * lui-même, vérifiée ici bout en bout avec de vraies entités `Incident`.
     */
    public function testReorderGivesTheSamePositionToBothLocalesOfAGroup(): void
    {
        $groupFr = $this->incident(Locale::FR, 0, 'Incident FR');
        $groupEn = new Incident(
            Locale::EN,
            'Incident EN',
            '1.0',
            new \DateTimeImmutable('2026-01-01'),
            'Impact.',
            'Cause.',
            'Resolution.',
            'Invariant.',
            0,
            $groupFr->getTranslationGroup(),
        );
        $other = $this->incident(Locale::FR, 1, 'Autre incident');
        $repository = new InMemoryIncidentRepository([$groupFr, $groupEn, $other]);

        $administrator = new IncidentAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $administrator->reorder([
            $other->getTranslationGroup()->toRfc4122(),
            $groupFr->getTranslationGroup()->toRfc4122(),
        ]);

        self::assertSame(1, $groupFr->getPosition());
        self::assertSame(1, $groupEn->getPosition());
        self::assertSame(0, $other->getPosition());
    }

    public function testReorderRefusesAKeyOutsideTheScope(): void
    {
        $incident = $this->incident(Locale::FR, 0, 'Incident');
        $repository = new InMemoryIncidentRepository([$incident]);

        $administrator = new IncidentAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $this->expectException(UnknownOrderEntryException::class);

        $administrator->reorder([$incident->getTranslationGroup()->toRfc4122(), Uuid::v7()->toRfc4122()]);
    }

    public function testReorderRefusesAnIncompleteList(): void
    {
        $first = $this->incident(Locale::FR, 0, 'Premier incident');
        $second = $this->incident(Locale::FR, 1, 'Second incident');
        $repository = new InMemoryIncidentRepository([$first, $second]);

        $administrator = new IncidentAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $this->expectException(IncompleteOrderException::class);

        $administrator->reorder([$first->getTranslationGroup()->toRfc4122()]);
    }
}
