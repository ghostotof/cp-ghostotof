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
use Closure;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les huit administrateurs de contenus localisés, réduits aux trois
 * opérations qui placent une entrée : `create()`, `update()` et `reorder()`.
 *
 * Partagé par OrderScopeLockCoverageTest (#389) et
 * UpdateReadsTheEntryUnderTheLockTest (#402) : un contexte ajouté ici est
 * couvert par les deux gardes à la fois. Un trait plutôt qu'une classe : les
 * administrateurs sont des services privés, que seul un KernelTestCase lit
 * dans le conteneur de test.
 *
 * @phpstan-type LocalizedContext array{
 *     create: Closure(Locale, ?Uuid): TranslatableContent,
 *     update: Closure(TranslatableContent, ?Uuid): TranslatableContent,
 *     reorder: Closure(list<string>): mixed,
 * }
 */
trait ProvidesLocalizedAdministrators
{
    /**
     * Chaque contexte : l'entité qui porte les positions (donc le périmètre
     * verrouillé), et ses opérations, construites sur le conteneur du test
     * démarré — d'où des closures, appelées après `bootKernel()`.
     *
     * @return iterable<string, array{0: class-string<TranslatableContent>, 1: Closure(): LocalizedContext}>
     */
    private static function localizedAdministrators(): iterable
    {
        yield 'Contribution' => [Contribution::class, static function (): array {
            $a = self::getContainer()->get(ContributionAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): Contribution => $a->create($l, 'T', 'P', 'R', 'https://example.com', 'S', 'B', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): Contribution => $a->update(self::as(Contribution::class, $e)->getId(), 'T', 'P', 'R', 'https://example.com', 'S', 'B', $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'Incident' => [Incident::class, static function (): array {
            $a = self::getContainer()->get(IncidentAdministratorInterface::class);
            $at = new DateTimeImmutable('2026-10-10');

            return [
                'create' => static fn (Locale $l, ?Uuid $g): Incident => $a->create($l, 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): Incident => $a->update(self::as(Incident::class, $e)->getId(), 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'QualityPrinciple' => [QualityPrinciple::class, static function (): array {
            $a = self::getContainer()->get(QualityPrincipleAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityPrinciple => $a->create($l, 'T', 'D', 'shield', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): QualityPrinciple => $a->update(self::as(QualityPrinciple::class, $e)->getId(), 'T', 'D', 'shield', $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'QualityTrait' => [QualityTrait::class, static function (): array {
            $a = self::getContainer()->get(QualityTraitAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityTrait => $a->create($l, 'L', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): QualityTrait => $a->update(self::as(QualityTrait::class, $e)->getId(), 'L', $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'AboutSiteCard' => [AboutSiteCard::class, static function (): array {
            $a = self::getContainer()->get(AboutSiteCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutSiteCard => $a->create($l, 'T', 'D', null, $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AboutSiteCard => $a->update(self::as(AboutSiteCard::class, $e)->getId(), 'T', 'D', null, $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'AboutMeCard' => [AboutMeCard::class, static function (): array {
            $a = self::getContainer()->get(AboutMeCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutMeCard => $a->create($l, AboutMeCardCategory::HOBBY, 'T', 'D', null, $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AboutMeCard => $a->update(self::as(AboutMeCard::class, $e)->getId(), 'T', 'D', null, $g),
                'reorder' => static function (array $keys) use ($a): void {
                    /** @var list<string> $keys clés de groupe, comme pour les autres contextes */
                    $a->reorder(AboutMeCardCategory::HOBBY, $keys);
                },
            ];
        }];
        yield 'AnonymousCvSection' => [AnonymousCvSection::class, static function (): array {
            $a = self::getContainer()->get(AnonymousCvSectionAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AnonymousCvSection => $a->create($l, 'T', 'S', 5, 'A', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): AnonymousCvSection => $a->update(self::as(AnonymousCvSection::class, $e)->getId(), 'T', 'S', 5, 'A', $g),
                'reorder' => $a->reorder(...),
            ];
        }];
        yield 'CaseStudy' => [CaseStudy::class, static function (): array {
            $a = self::getContainer()->get(CaseStudyAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): CaseStudy => $a->create($l, 'T', 'P', 'S', 'C', 'M', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g): CaseStudy => $a->update(self::as(CaseStudy::class, $e)->getId(), 'T', 'P', 'S', 'C', 'M', $g),
                'reorder' => $a->reorder(...),
            ];
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
}
