<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutMeCardAdministrator;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AboutMeCardAdministratorTest extends TestCase
{
    public function testCreatePersistsCard(): void
    {
        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(AboutMeCard::class));

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

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

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $updated = $administrator->update($card->getId(), 'Moto', 'New description.', 'motorbike', null);

        self::assertSame('Moto', $updated->getTitle());
        self::assertSame(AboutMeCardCategory::HOBBY, $updated->getCategory());
    }

    public function testUpdateThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->update(Uuid::v7(), 'x', 'x', 'x', null);
    }

    public function testDeleteRemovesCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($card->getId())->willReturn($card);
        $repository->expects(self::once())->method('remove')->with($card);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $administrator->delete($card->getId());
    }

    public function testDeleteThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

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

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $administrator->reorder(AboutMeCardCategory::TECHNICAL, [$second->getTranslationGroup()->toRfc4122(), $first->getTranslationGroup()->toRfc4122()]);

        self::assertSame(1, $first->getPosition());
        self::assertSame(0, $second->getPosition());
    }
}
