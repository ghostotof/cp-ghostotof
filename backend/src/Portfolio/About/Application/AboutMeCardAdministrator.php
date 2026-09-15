<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AboutMeCardAdministrator implements AboutMeCardAdministratorInterface
{
    public function __construct(
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
    ) {
    }

    public function create(
        Locale $locale,
        AboutMeCardCategory $category,
        string $title,
        string $description,
        ?string $iconKey,
        ?Uuid $translationGroup = null,
    ): AboutMeCard {
        $card = new AboutMeCard($locale, $category, $title, $description, $iconKey, $this->positionFor($locale, $category, $translationGroup), $translationGroup);

        $this->aboutMeCardRepository->save($card);

        return $card;
    }

    public function update(Uuid $id, string $title, string $description, ?string $iconKey, ?Uuid $translationGroup): AboutMeCard
    {
        $card = $this->aboutMeCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutMeCardNotFoundException::forId($id);
        }

        if (null === $translationGroup) {
            // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
            // le chargement du périmètre — le même qu'à la création sans groupe.
            $this->contentPlacement->detach(
                $card,
                $this->membersOf($card->getTranslationGroup(), $card->getCategory()),
                $this->aboutMeCardRepository->findByCategory($card->getCategory()),
            );
        } else {
            $this->contentPlacement->reattach(
                $card,
                $translationGroup,
                $this->membersOf($translationGroup, $card->getCategory()),
            );
        }
        $card->update($title, $description, $iconKey);
        $this->aboutMeCardRepository->save($card);

        return $card;
    }

    public function delete(Uuid $id): void
    {
        $card = $this->aboutMeCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutMeCardNotFoundException::forId($id);
        }

        $this->aboutMeCardRepository->remove($card);
    }

    /**
     * Spec 0004 D4/D5 : le périmètre chargé est la catégorie, pas la table
     * entière — `findByCategory()` — si bien que les deux autres catégories ne
     * sont ni lues ni écrites par cet appel.
     */
    public function reorder(AboutMeCardCategory $category, array $keys): void
    {
        $scope = $this->aboutMeCardRepository->findByCategory($category);

        $this->orderAssigner->assign($scope, $keys);

        $this->aboutMeCardRepository->saveAll($scope);
    }

    /**
     * Spec 0004 D3 : le périmètre d'ordre d'une carte « moi » est sa
     * **catégorie**, toutes langues confondues — c'est ce que servent les trois
     * tableaux de la page À propos, et donc ce que numérotent leurs positions.
     */
    private function positionFor(Locale $locale, AboutMeCardCategory $category, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->aboutMeCardRepository->findByCategory($category));
        }

        return $this->contentPlacement->inGroup($translationGroup, $locale, $this->membersOf($translationGroup, $category));
    }

    /**
     * Les entrées du groupe **dans cette catégorie**. Un groupe ne peut pas
     * chevaucher deux catégories : une même position y vaudrait dans deux
     * tableaux différents, et déplacer l'un déplacerait l'autre. Un groupe
     * d'une autre catégorie est donc filtré ici, et ressort comme inconnu du
     * périmètre — 422, `unknown-translation-group`.
     *
     * @return list<AboutMeCard>
     */
    private function membersOf(Uuid $translationGroup, AboutMeCardCategory $category): array
    {
        return array_values(array_filter(
            $this->aboutMeCardRepository->findByTranslationGroup($translationGroup),
            static fn (AboutMeCard $card): bool => $card->getCategory() === $category,
        ));
    }
}
