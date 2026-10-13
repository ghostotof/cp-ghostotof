<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Infrastructure\Doctrine;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Portfolio\About\Support\CommitsSpreadMeCardGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #561 : `findInOtherCategoriesSharingAGroupWith()` est ce que
 * `reorder()` lit pour refuser une catégorie qui porte un groupe réparti. Elle
 * ne rend que les membres égarés — ni les cartes de la catégorie, ni celles
 * d'une autre catégorie dont le groupe y reste entier.
 */
final class AboutMeCardRepositoryTest extends KernelTestCase
{
    use CommitsSpreadMeCardGroup;

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM about_me_card');
        parent::tearDown();
    }

    public function testFindsOnlyTheMembersOfTheCategorysGroupsThatLiveInAnotherCategory(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$french, $english] = self::commitSpreadGroup($entityManager);
        $this->saveWholeGroup($entityManager, AboutMeCardCategory::HOBBY, 1);
        $this->saveWholeGroup($entityManager, AboutMeCardCategory::TECHNICAL, 1);
        $entityManager->clear();

        $repository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        self::assertSame([$english->getId()->toRfc4122()], $this->idsOf($repository->findInOtherCategoriesSharingAGroupWith(AboutMeCardCategory::HOBBY)));
        self::assertSame([$french->getId()->toRfc4122()], $this->idsOf($repository->findInOtherCategoriesSharingAGroupWith(AboutMeCardCategory::TECHNICAL)));
        self::assertSame([], $repository->findInOtherCategoriesSharingAGroupWith(AboutMeCardCategory::PERSONAL));
    }

    /**
     * Un contenu FR/EN entier, dans une seule catégorie : il ne doit jamais
     * sortir comme égaré.
     */
    private function saveWholeGroup(EntityManagerInterface $entityManager, AboutMeCardCategory $category, int $position): void
    {
        $french = new AboutMeCard(Locale::FR, $category, 'Titre', 'Description.', null, $position);
        $entityManager->persist($french);
        $entityManager->persist(new AboutMeCard(Locale::EN, $category, 'Title', 'Description.', null, $position, $french->getTranslationGroup()));
        $entityManager->flush();
    }

    /**
     * @param list<AboutMeCard> $cards
     *
     * @return list<string>
     */
    private function idsOf(array $cards): array
    {
        return array_map(static fn (AboutMeCard $card): string => $card->getId()->toRfc4122(), $cards);
    }
}
