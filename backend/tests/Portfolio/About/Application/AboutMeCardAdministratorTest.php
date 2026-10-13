<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutMeCardAdministrator;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardCategoryIsImmutableException;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Portfolio\Shared\Support\ImmediateOrderScopeLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AboutMeCardAdministratorTest extends TestCase
{
    public function testCreatePersistsCard(): void
    {
        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(AboutMeCard::class));

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $card = $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code');

        self::assertSame('Développeur', $card->getTitle());
        self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
    }

    public function testUpdateChangesExistingCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($card->getId())->willReturn($card);
        $repository->expects(self::once())->method('save')->with($card);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $updated = $administrator->update($card->getId(), AboutMeCardCategory::HOBBY, 'Moto', 'New description.', 'motorbike', null);

        self::assertSame('Moto', $updated->getTitle());
        self::assertSame(AboutMeCardCategory::HOBBY, $updated->getCategory());
    }

    public function testUpdateThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->update(Uuid::v7(), AboutMeCardCategory::HOBBY, 'x', 'x', 'x', null);
    }

    /**
     * Issue #578 : une carte ne change pas de catégorie. Le refus tombe juste
     * après la relecture, avant toute autre lecture et tout changement en
     * mémoire — le détachement que `translationGroup: null` aurait demandé
     * n'a pas lieu, et rien n'est enregistré.
     */
    public function testUpdateWithAnotherCategoryIsRefusedBeforeAnyChange(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 2);
        $group = $card->getTranslationGroup();

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($card);
        $repository->expects(self::never())->method('findByTranslationGroup');
        $repository->expects(self::never())->method('findByCategory');
        $repository->expects(self::never())->method('save');

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        try {
            $administrator->update($card->getId(), AboutMeCardCategory::HOBBY, 'Musique', 'Autre description.', 'guitar', null);
            self::fail('Le changement de catégorie a été accepté.');
        } catch (AboutMeCardCategoryIsImmutableException $refusal) {
            self::assertStringContainsString('technical', $refusal->getMessage());
            self::assertStringContainsString('hobby', $refusal->getMessage());
            self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
            self::assertSame('Développeur', $card->getTitle(), 'Le texte a changé en mémoire avant le refus.');
            self::assertSame(2, $card->getPosition(), 'La carte a changé de position en mémoire avant le refus.');
            self::assertTrue($group->equals($card->getTranslationGroup()), 'La carte a changé de groupe en mémoire avant le refus.');
        }
    }

    /**
     * Ordre des gardes (#578) : le refus de catégorie ne dépend que du corps,
     * il passe avant celui d'un groupe réparti (#561), qui demande de lire le
     * groupe. Le groupe réparti reste refusé, sous son nom, à la requête
     * valide suivante.
     */
    public function testTheCategoryRefusalComesBeforeTheSpreadGroupRefusal(): void
    {
        [$french] = $this->spreadGroup();
        $card = new AboutMeCard(Locale::EN, AboutMeCardCategory::HOBBY, 'Music', 'Description.', 'guitar', 3);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($card);
        $repository->expects(self::never())->method('findByTranslationGroup');
        $repository->expects(self::never())->method('save');

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $this->expectException(AboutMeCardCategoryIsImmutableException::class);

        $administrator->update($card->getId(), AboutMeCardCategory::PERSONAL, 'Music', 'Description.', 'guitar', $french->getTranslationGroup());
    }

    public function testDeleteRemovesCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($card->getId())->willReturn($card);
        $repository->expects(self::once())->method('remove')->with($card);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $administrator->delete($card->getId());
    }

    public function testDeleteThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->delete(Uuid::v7());
    }

    /**
     * Spec 0004 D3/D4/D5 : le périmètre d'ordre d'une carte « moi » est sa
     * catégorie, pas la table entière — `reorder()` charge `findByCategory()`,
     * jamais `findAll()`, si bien que réordonner `TECHNICAL` ne lit ni
     * n'écrit rien de `HOBBY`/`PERSONAL` : elles restent absentes de
     * l'unique appel à `saveAll()`.
     */
    public function testReorderScopesToTheCategoryAndSavesItInOneCall(): void
    {
        $first = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $second = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'DevOps', 'Description.', 'server', 1);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByCategory')
            ->with(AboutMeCardCategory::TECHNICAL)
            ->willReturn([$first, $second]);
        $repository->expects(self::never())->method('findAll');
        $repository->expects(self::never())->method('save');
        $repository->expects(self::once())->method('saveAll')->with([$first, $second]);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $administrator->reorder(AboutMeCardCategory::TECHNICAL, [$second->getTranslationGroup()->toRfc4122(), $first->getTranslationGroup()->toRfc4122()]);

        self::assertSame(1, $first->getPosition());
        self::assertSame(0, $second->getPosition());
    }

    /**
     * Issue #561 : un groupe réparti sur deux catégories (FR dans `HOBBY`, EN
     * dans `TECHNICAL`, écrit avant la garde de #501) n'offre plus « Créer la
     * version EN » vers le 500 brut de l'index unique `(translation_group,
     * locale)` : le refus est nommé, et tombe avant toute écriture.
     */
    public function testCreatingAVersionInAGroupSpreadOverTwoCategoriesIsRefusedBeforeAnyWrite(): void
    {
        [$french, $english] = $this->spreadGroup();

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::atLeastOnce())->method('findByTranslationGroup')->with($french->getTranslationGroup())->willReturn([$english, $french]);
        $repository->expects(self::never())->method('save');

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $this->expectException(TranslationGroupAcrossSeveralScopesException::class);

        $administrator->create(Locale::EN, AboutMeCardCategory::HOBBY, 'Music', 'Description.', 'guitar', $french->getTranslationGroup());
    }

    /**
     * Même défaut par le sélecteur « Version de » d'un `PUT`, qui ne propose que
     * les cartes de la catégorie : une carte EN de `HOBBY` rattachée au FR d'un
     * groupe dont l'EN vit dans `TECHNICAL` heurtait l'index unique.
     */
    public function testAttachingToAGroupSpreadOverTwoCategoriesIsRefusedBeforeAnyChange(): void
    {
        [$french, $english] = $this->spreadGroup();
        $card = new AboutMeCard(Locale::EN, AboutMeCardCategory::HOBBY, 'Music', 'Description.', 'guitar', 3);
        $ownGroup = $card->getTranslationGroup();

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($card);
        $repository->expects(self::atLeastOnce())->method('findByTranslationGroup')->with($french->getTranslationGroup())->willReturn([$english, $french]);
        $repository->expects(self::never())->method('save');

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        try {
            $administrator->update($card->getId(), AboutMeCardCategory::HOBBY, 'Music', 'Description.', 'guitar', $french->getTranslationGroup());
            self::fail('Le rattachement à un groupe réparti a été accepté.');
        } catch (TranslationGroupAcrossSeveralScopesException) {
            self::assertTrue($ownGroup->equals($card->getTranslationGroup()), 'La carte a changé de groupe en mémoire avant le refus.');
            self::assertSame(3, $card->getPosition(), 'La carte a changé de position en mémoire avant le refus.');
        }
    }

    /**
     * Garde de #561 : éditer le texte d'un membre d'un groupe réparti renvoie
     * son propre groupe (le frontend le reprend dès qu'une carte, toutes
     * catégories confondues, le partage). Ce n'est pas un rattachement : rien
     * à refuser, et rien à relire — le refus de #561 ne doit pas bloquer
     * l'édition des cartes qu'il demande de corriger.
     */
    public function testEditingTheTextOfASpreadGroupMemberWithItsOwnGroupStillSaves(): void
    {
        [$french] = $this->spreadGroup();

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($french);
        $repository->expects(self::never())->method('findByTranslationGroup');
        $repository->expects(self::once())->method('save')->with($french);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $updated = $administrator->update($french->getId(), AboutMeCardCategory::HOBBY, 'Guitare', 'Autre description.', 'guitar', $french->getTranslationGroup());

        self::assertSame('Guitare', $updated->getTitle());
        self::assertSame(0, $updated->getPosition());
    }

    /**
     * Issue #561 : « Détacher » lit le groupe toutes catégories confondues. Sur
     * un membre d'un groupe réparti, il détache donc réellement — groupe neuf,
     * fin de sa catégorie (#169) — au lieu du non-geste silencieux d'avant :
     * c'est un second chemin de correction.
     */
    public function testDetachingAMemberOfAGroupSpreadOverTwoCategoriesReallyDetachesIt(): void
    {
        [$french, $english] = $this->spreadGroup();
        $other = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Lecture', 'Description.', 'book', 4);
        $spreadGroup = $french->getTranslationGroup();

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($french);
        $repository->expects(self::atLeastOnce())->method('findByTranslationGroup')->with($spreadGroup)->willReturn([$english, $french]);
        $repository->expects(self::atLeastOnce())->method('findByCategory')->with(AboutMeCardCategory::HOBBY)->willReturn([$french, $other]);
        $repository->expects(self::once())->method('save')->with($french);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        $administrator->update($french->getId(), AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', null);

        self::assertFalse($spreadGroup->equals($french->getTranslationGroup()), 'Le détachement a été sauté : la carte est restée dans le groupe réparti.');
        self::assertSame(5, $french->getPosition(), "La carte détachée n'est pas partie en fin de sa catégorie (#169).");
        self::assertTrue($spreadGroup->equals($english->getTranslationGroup()), "L'autre membre a changé de groupe.");
    }

    /**
     * Issue #561 : réordonner une catégorie qui porte un groupe réparti est
     * refusé **avant toute écriture** et **avant** la règle d'ensemble exact
     * (422) — un défaut du pipeline ne se cache pas derrière une erreur du
     * client. Avant, le refus ne tombait qu'au COMMIT (`23T91`), et seulement
     * si le membre égaré changeait de position.
     */
    public function testReorderingACategoryHoldingASpreadGroupIsRefusedBeforeAnyChangeAndBeforeTheExactSetRule(): void
    {
        [$french, $english] = $this->spreadGroup();
        $other = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Lecture', 'Description.', 'book', 1);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->method('findByCategory')->willReturn([$french, $other]);
        $repository->expects(self::atLeastOnce())->method('findInOtherCategoriesSharingAGroupWith')->with(AboutMeCardCategory::HOBBY)->willReturn([$english]);
        $repository->expects(self::never())->method('saveAll');

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner(), new ImmediateOrderScopeLock());

        try {
            // Une clé inconnue : la règle d'ensemble exact répondrait 422 si elle passait avant.
            $administrator->reorder(AboutMeCardCategory::HOBBY, [Uuid::v7()->toRfc4122()]);
            self::fail("Le réordonnancement d'une catégorie qui porte un groupe réparti a été accepté.");
        } catch (TranslationGroupAcrossSeveralScopesException $refusal) {
            self::assertStringContainsString($french->getTranslationGroup()->toRfc4122(), $refusal->getMessage());
            self::assertSame(0, $french->getPosition(), 'Une position a changé en mémoire avant le refus.');
            self::assertSame(1, $other->getPosition(), 'Une position a changé en mémoire avant le refus.');
        }
    }

    /**
     * Un groupe réparti sur deux catégories, tel qu'il peut rester d'avant la
     * garde de #501 : FR dans `HOBBY`, EN dans `TECHNICAL`, à la position 0.
     *
     * @return array{0: AboutMeCard, 1: AboutMeCard}
     */
    private function spreadGroup(): array
    {
        $french = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);
        $english = new AboutMeCard(Locale::EN, AboutMeCardCategory::TECHNICAL, 'Music', 'Description.', 'guitar', 0, $french->getTranslationGroup());

        return [$french, $english];
    }
}
