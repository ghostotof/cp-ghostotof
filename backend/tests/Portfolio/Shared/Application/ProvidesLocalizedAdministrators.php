<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Application;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Exception\IncidentNotFoundException;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Entity\QualityTrait;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Closure;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Les huit administrateurs de contenus localisés, réduits aux quatre
 * opérations qui écrivent dans le périmètre d'ordre : `create()`, `update()`,
 * `delete()` et `reorder()`, plus l'exception qu'ils lèvent sur une entrée
 * introuvable.
 *
 * Partagé par OrderScopeLockCoverageTest (#389), ScopeLockReadsCommittedStateTest
 * (#402) et LocaleIsImmutableOnUpdateTest (#582, pour qui `update` accepte une
 * langue, celle de l'entrée par défaut) : un contexte ajouté ici est couvert
 * par ces gardes à la fois. Un trait plutôt qu'une classe : les
 * administrateurs sont des services privés, que seul un KernelTestCase lit
 * dans le conteneur de test.
 *
 * @phpstan-type LocalizedContext array{
 *     create: Closure(Locale, ?Uuid): TranslatableContent,
 *     update: Closure(TranslatableContent, ?Uuid, ?Locale=): TranslatableContent,
 *     delete: Closure(TranslatableContent): void,
 *     reorder: Closure(list<string>): mixed,
 *     notFound: class-string<Throwable>,
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
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): Contribution => $a->update(self::as(Contribution::class, $e)->getId(), $l ?? $e->getLocale(), 'T', 'P', 'R', 'https://example.com', 'S', 'B', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(Contribution::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => ContributionNotFoundException::class,
            ];
        }];
        yield 'Incident' => [Incident::class, static function (): array {
            $a = self::getContainer()->get(IncidentAdministratorInterface::class);
            $at = new DateTimeImmutable('2026-10-10');

            return [
                'create' => static fn (Locale $l, ?Uuid $g): Incident => $a->create($l, 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): Incident => $a->update(self::as(Incident::class, $e)->getId(), $l ?? $e->getLocale(), 'T', '1.0.0', $at, 'I', 'C', 'R', 'V', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(Incident::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => IncidentNotFoundException::class,
            ];
        }];
        yield 'QualityPrinciple' => [QualityPrinciple::class, static function (): array {
            $a = self::getContainer()->get(QualityPrincipleAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityPrinciple => $a->create($l, 'T', 'D', 'shield', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): QualityPrinciple => $a->update(self::as(QualityPrinciple::class, $e)->getId(), $l ?? $e->getLocale(), 'T', 'D', 'shield', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(QualityPrinciple::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => QualityPrincipleNotFoundException::class,
            ];
        }];
        yield 'QualityTrait' => [QualityTrait::class, static function (): array {
            $a = self::getContainer()->get(QualityTraitAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): QualityTrait => $a->create($l, 'L', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): QualityTrait => $a->update(self::as(QualityTrait::class, $e)->getId(), $l ?? $e->getLocale(), 'L', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(QualityTrait::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => QualityTraitNotFoundException::class,
            ];
        }];
        yield 'AboutSiteCard' => [AboutSiteCard::class, static function (): array {
            $a = self::getContainer()->get(AboutSiteCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutSiteCard => $a->create($l, 'T', 'D', null, $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): AboutSiteCard => $a->update(self::as(AboutSiteCard::class, $e)->getId(), $l ?? $e->getLocale(), 'T', 'D', null, $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(AboutSiteCard::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => AboutSiteCardNotFoundException::class,
            ];
        }];
        yield 'AboutMeCard' => [AboutMeCard::class, static function (): array {
            $a = self::getContainer()->get(AboutMeCardAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AboutMeCard => $a->create($l, AboutMeCardCategory::HOBBY, 'T', 'D', null, $g),
                'update' => static function (TranslatableContent $e, ?Uuid $g, ?Locale $l = null) use ($a): AboutMeCard {
                    $card = self::as(AboutMeCard::class, $e);

                    return $a->update($card->getId(), $l ?? $card->getLocale(), $card->getCategory(), 'T', 'D', null, $g);
                },
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(AboutMeCard::class, $e)->getId());
                },
                'reorder' => static function (array $keys) use ($a): void {
                    /** @var list<string> $keys clés de groupe, comme pour les autres contextes */
                    $a->reorder(AboutMeCardCategory::HOBBY, $keys);
                },
                'notFound' => AboutMeCardNotFoundException::class,
            ];
        }];
        yield 'AnonymousCvSection' => [AnonymousCvSection::class, static function (): array {
            $a = self::getContainer()->get(AnonymousCvSectionAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): AnonymousCvSection => $a->create($l, 'T', 'S', 5, 'A', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): AnonymousCvSection => $a->update(self::as(AnonymousCvSection::class, $e)->getId(), $l ?? $e->getLocale(), 'T', 'S', 5, 'A', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(AnonymousCvSection::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => AnonymousCvSectionNotFoundException::class,
            ];
        }];
        yield 'CaseStudy' => [CaseStudy::class, static function (): array {
            $a = self::getContainer()->get(CaseStudyAdministratorInterface::class);

            return [
                'create' => static fn (Locale $l, ?Uuid $g): CaseStudy => $a->create($l, 'T', 'P', 'S', 'C', 'M', $g),
                'update' => static fn (TranslatableContent $e, ?Uuid $g, ?Locale $l = null): CaseStudy => $a->update(self::as(CaseStudy::class, $e)->getId(), $l ?? $e->getLocale(), 'T', 'P', 'S', 'C', 'M', $g),
                'delete' => static function (TranslatableContent $e) use ($a): void {
                    $a->delete(self::as(CaseStudy::class, $e)->getId());
                },
                'reorder' => $a->reorder(...),
                'notFound' => CaseStudyNotFoundException::class,
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
