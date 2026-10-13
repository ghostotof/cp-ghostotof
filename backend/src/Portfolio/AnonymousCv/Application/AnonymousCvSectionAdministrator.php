<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslatableContentLocaleIsImmutableException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AnonymousCvSectionAdministrator implements AnonymousCvSectionAdministratorInterface
{
    /**
     * Périmètre d'ordre, verrouillé par toute écriture : celles qui placent
     * une entrée (issue #389), et delete(), pour qu'une suppression ne valide
     * pas sous une autre écriture (issue #499). La table entière, toutes
     * langues confondues.
     */
    private const string ORDER_SCOPE = AnonymousCvSection::class;

    public function __construct(
        private AnonymousCvSectionRepositoryInterface $sectionRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
        private OrderScopeLockInterface $orderScopeLock,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        ?Uuid $translationGroup = null,
    ): AnonymousCvSection {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($locale, $title, $skills, $yearsOfExperience, $achievements, $translationGroup): AnonymousCvSection {
            $section = new AnonymousCvSection($locale, $title, $skills, $yearsOfExperience, $achievements, $this->positionFor($locale, $translationGroup), $translationGroup);

            $this->sectionRepository->save($section);

            return $section;
        });
    }

    public function update(
        Uuid $id,
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        ?Uuid $translationGroup,
    ): AnonymousCvSection {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id, $locale, $title, $skills, $yearsOfExperience, $achievements, $translationGroup): AnonymousCvSection {
            $section = $this->sectionRepository->findOneById($id);

            if (null === $section) {
                throw AnonymousCvSectionNotFoundException::forId($id);
            }

            // Issue #582 : une entrée ne change pas de langue. Refusé avant
            // toute autre lecture et tout changement en mémoire (#549), donc
            // avant le détachement ou le rattachement que demande le corps.
            if ($section->getLocale() !== $locale) {
                throw TranslatableContentLocaleIsImmutableException::refused();
            }

            if (null === $translationGroup) {
                // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
                // le chargement du périmètre — le même qu'à la création sans groupe.
                $this->contentPlacement->detach(
                    $section,
                    $this->sectionRepository->findByTranslationGroup($section->getTranslationGroup()),
                    $this->sectionRepository->findAll(),
                );
            } else {
                $this->contentPlacement->reattach(
                    $section,
                    $translationGroup,
                    $this->sectionRepository->findByTranslationGroup($translationGroup),
                );
            }
            $section->update($title, $skills, $yearsOfExperience, $achievements);
            $this->sectionRepository->save($section);

            return $section;
        });
    }

    public function delete(Uuid $id): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id): void {
            $section = $this->sectionRepository->findOneById($id);

            if (null === $section) {
                throw AnonymousCvSectionNotFoundException::forId($id);
            }

            $this->sectionRepository->remove($section);
        });
    }

    public function reorder(array $keys): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($keys): void {
            $scope = $this->sectionRepository->findAll();

            $this->orderAssigner->assign($scope, $keys);

            $this->sectionRepository->saveAll($scope);
        });
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->sectionRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->sectionRepository->findByTranslationGroup($translationGroup),
        );
    }
}
