<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Domain\Service;

use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Portfolio\Shared\Support\FakeTranslatableContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D3 : la règle de placement, une fois, pour les huit contextes.
 */
final class ContentPlacementTest extends TestCase
{
    public function testAnEmptyScopeStartsAtZero(): void
    {
        self::assertSame(0, (new ContentPlacement())->atEndOf([]));
    }

    /**
     * `max + 1`, pas `count` : une table dont une entrée du milieu a été
     * supprimée garde des positions à trous, et `count` y rendrait une
     * position déjà prise.
     */
    public function testANewEntryGoesAfterTheLastPositionOfTheScope(): void
    {
        $scope = [
            new FakeTranslatableContent(Locale::FR, 0),
            new FakeTranslatableContent(Locale::FR, 5),
            new FakeTranslatableContent(Locale::EN, 5),
        ];

        self::assertSame(6, (new ContentPlacement())->atEndOf($scope));
    }

    public function testAnEntryAttachedToAGroupInheritsItsPosition(): void
    {
        $group = Uuid::v7();
        $members = [new FakeTranslatableContent(Locale::FR, 3, $group)];

        self::assertSame(3, (new ContentPlacement())->inGroup($group, Locale::EN, $members));
    }

    public function testAGroupUnknownToTheScopeIsRefused(): void
    {
        $this->expectException(UnknownTranslationGroupException::class);

        (new ContentPlacement())->inGroup(Uuid::v7(), Locale::EN, []);
    }

    public function testAGroupThatAlreadyCarriesTheLocaleIsRefused(): void
    {
        $group = Uuid::v7();
        $members = [new FakeTranslatableContent(Locale::EN, 3, $group)];

        $this->expectException(TranslationAlreadyExistsException::class);

        (new ContentPlacement())->inGroup($group, Locale::EN, $members);
    }

    public function testReattachingToAnotherGroupInheritsItsPosition(): void
    {
        $entry = new FakeTranslatableContent(Locale::EN, 9);
        $target = Uuid::v7();
        $members = [new FakeTranslatableContent(Locale::FR, 2, $target)];

        (new ContentPlacement())->reattach($entry, $target, $members);

        self::assertTrue($target->equals($entry->getTranslationGroup()));
        self::assertSame(2, $entry->getPosition());
    }

    /**
     * Détacher sépare de ses traductions ; cela ne déplace pas.
     */
    public function testDetachingKeepsThePositionAndForgesAFreshGroup(): void
    {
        $group = Uuid::v7();
        $entry = new FakeTranslatableContent(Locale::FR, 4, $group);
        $sibling = new FakeTranslatableContent(Locale::EN, 4, $group);

        (new ContentPlacement())->reattach($entry, null, [$entry, $sibling]);

        self::assertFalse($group->equals($entry->getTranslationGroup()));
        self::assertSame(4, $entry->getPosition());
    }

    /**
     * Une entrée déjà seule dans son groupe n'a rien à détacher : lui forger un
     * groupe neuf changerait la valeur que l'appelant vient de lire, sans rien
     * apporter.
     */
    public function testDetachingAnEntryWithoutTranslationsDoesNothing(): void
    {
        $group = Uuid::v7();
        $entry = new FakeTranslatableContent(Locale::FR, 4, $group);

        (new ContentPlacement())->reattach($entry, null, [$entry]);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testReattachingToItsOwnGroupIsANoOp(): void
    {
        $group = Uuid::v7();
        $entry = new FakeTranslatableContent(Locale::FR, 4, $group);
        $sibling = new FakeTranslatableContent(Locale::EN, 4, $group);

        (new ContentPlacement())->reattach($entry, $group, [$entry, $sibling]);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
        self::assertSame(4, $entry->getPosition());
    }

    public function testReattachingToAGroupThatAlreadyCarriesTheLocaleIsRefused(): void
    {
        $entry = new FakeTranslatableContent(Locale::EN, 9);
        $target = Uuid::v7();
        $members = [new FakeTranslatableContent(Locale::EN, 2, $target)];

        $this->expectException(TranslationAlreadyExistsException::class);

        (new ContentPlacement())->reattach($entry, $target, $members);
    }
}
