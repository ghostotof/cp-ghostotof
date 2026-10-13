<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslatableContentLocaleIsImmutableException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AboutSiteCardAdministrator implements AboutSiteCardAdministratorInterface
{
    /**
     * Périmètre d'ordre, verrouillé par toute écriture : celles qui placent
     * une entrée (issue #389), et delete(), pour qu'une suppression ne valide
     * pas sous une autre écriture (issue #499). La table entière, toutes
     * langues confondues.
     */
    private const string ORDER_SCOPE = AboutSiteCard::class;

    public function __construct(
        private AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
        private OrderScopeLockInterface $orderScopeLock,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $description,
        ?string $iconKey,
        ?Uuid $translationGroup = null,
    ): AboutSiteCard {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($locale, $title, $description, $iconKey, $translationGroup): AboutSiteCard {
            $card = new AboutSiteCard($locale, $title, $description, $iconKey, $this->positionFor($locale, $translationGroup), $translationGroup);

            $this->aboutSiteCardRepository->save($card);

            return $card;
        });
    }

    public function update(Uuid $id, Locale $locale, string $title, string $description, ?string $iconKey, ?Uuid $translationGroup): AboutSiteCard
    {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id, $locale, $title, $description, $iconKey, $translationGroup): AboutSiteCard {
            $card = $this->aboutSiteCardRepository->findOneById($id);

            if (null === $card) {
                throw AboutSiteCardNotFoundException::forId($id);
            }

            // Issue #582 : une entrée ne change pas de langue. Refusé avant
            // toute autre lecture et tout changement en mémoire (#549), donc
            // avant le détachement ou le rattachement qu'il demanderait.
            if ($card->getLocale() !== $locale) {
                throw TranslatableContentLocaleIsImmutableException::refused();
            }

            if (null === $translationGroup) {
                // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
                // le chargement du périmètre — le même qu'à la création sans groupe.
                $this->contentPlacement->detach(
                    $card,
                    $this->aboutSiteCardRepository->findByTranslationGroup($card->getTranslationGroup()),
                    $this->aboutSiteCardRepository->findAll(),
                );
            } else {
                $this->contentPlacement->reattach(
                    $card,
                    $translationGroup,
                    $this->aboutSiteCardRepository->findByTranslationGroup($translationGroup),
                );
            }
            $card->update($title, $description, $iconKey);
            $this->aboutSiteCardRepository->save($card);

            return $card;
        });
    }

    public function delete(Uuid $id): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id): void {
            $card = $this->aboutSiteCardRepository->findOneById($id);

            if (null === $card) {
                throw AboutSiteCardNotFoundException::forId($id);
            }

            $this->aboutSiteCardRepository->remove($card);
        });
    }

    public function reorder(array $keys): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($keys): void {
            $scope = $this->aboutSiteCardRepository->findAll();

            $this->orderAssigner->assign($scope, $keys);

            $this->aboutSiteCardRepository->saveAll($scope);
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
            return $this->contentPlacement->atEndOf($this->aboutSiteCardRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->aboutSiteCardRepository->findByTranslationGroup($translationGroup),
        );
    }
}
