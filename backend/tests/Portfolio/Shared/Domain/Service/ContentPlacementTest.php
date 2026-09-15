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
     * Issue #169 : détacher sépare de ses traductions ET envoie l'entrée en fin
     * de périmètre. La garder à sa position laisserait deux clés sur la même
     * position dès que l'ancien groupe reçoit à nouveau cette langue.
     */
    public function testDetachingForgesAFreshGroupAndMovesToTheEndOfTheScope(): void
    {
        $group = Uuid::v7();
        $entry = new FakeTranslatableContent(Locale::FR, 4, $group);
        $sibling = new FakeTranslatableContent(Locale::EN, 4, $group);
        $scope = [new FakeTranslatableContent(Locale::FR, 0), $entry, $sibling, new FakeTranslatableContent(Locale::EN, 7)];

        (new ContentPlacement())->detach($entry, [$entry, $sibling], $scope);

        self::assertFalse($group->equals($entry->getTranslationGroup()));
        self::assertSame(8, $entry->getPosition());
        self::assertSame(4, $sibling->getPosition(), 'La traduction restée dans le groupe ne bouge pas.');
    }

    /**
     * Régression #169, le scénario complet : détacher FR de G1, puis « créer la
     * version FR » sur G1 — la nouvelle FR hérite de la position de G1, et la
     * FR détachée ne doit pas la partager.
     */
    public function testDetachingThenRecreatingTheLocaleNeverYieldsTwoEntriesOnOnePosition(): void
    {
        $placement = new ContentPlacement();
        $group = Uuid::v7();
        $french = new FakeTranslatableContent(Locale::FR, 0, $group);
        $english = new FakeTranslatableContent(Locale::EN, 0, $group);
        $scope = [$french, $english, new FakeTranslatableContent(Locale::FR, 1), new FakeTranslatableContent(Locale::EN, 1)];

        $placement->detach($french, [$french, $english], $scope);
        $recreated = $placement->inGroup($group, Locale::FR, [$english]);

        self::assertNotSame($french->getPosition(), $recreated);
    }

    /**
     * Toutes les entrées d'un groupe partagent sa position ; `inGroup()` en
     * dépend. Un groupe hétérogène est un défaut du pipeline, pas une saisie :
     * il doit surfacer, pas être arbitré en silence par `$members[0]`.
     */
    public function testAGroupWhoseMembersDisagreeOnThePositionIsABug(): void
    {
        $group = Uuid::v7();
        $members = [new FakeTranslatableContent(Locale::FR, 3, $group), new FakeTranslatableContent(Locale::EN, 5, $group)];

        $this->expectException(\LogicException::class);

        (new ContentPlacement())->inGroup($group, Locale::EN, $members);
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

        (new ContentPlacement())->detach($entry, [$entry], [$entry, new FakeTranslatableContent(Locale::FR, 9)]);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
        self::assertSame(4, $entry->getPosition());
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
