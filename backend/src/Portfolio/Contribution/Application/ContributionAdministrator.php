<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslatableContentLocaleIsImmutableException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class ContributionAdministrator implements ContributionAdministratorInterface
{
    /**
     * Périmètre d'ordre, verrouillé par toute écriture : celles qui placent
     * une entrée (issue #389), et delete(), pour qu'une suppression ne valide
     * pas sous une autre écriture (issue #499). La table entière, toutes
     * langues confondues.
     */
    private const string ORDER_SCOPE = Contribution::class;

    public function __construct(
        private ContributionRepositoryInterface $contributionRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
        private OrderScopeLockInterface $orderScopeLock,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        ?Uuid $translationGroup = null,
    ): Contribution {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($locale, $title, $project, $reference, $url, $summary, $body, $translationGroup): Contribution {
            $contribution = new Contribution($locale, $title, $project, $reference, $url, $summary, $body, $this->positionFor($locale, $translationGroup), $translationGroup);

            $this->contributionRepository->save($contribution);

            return $contribution;
        });
    }

    public function update(
        Uuid $id,
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        ?Uuid $translationGroup,
    ): Contribution {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id, $locale, $title, $project, $reference, $url, $summary, $body, $translationGroup): Contribution {
            $contribution = $this->contributionRepository->findOneById($id);

            if (null === $contribution) {
                throw ContributionNotFoundException::forId($id);
            }

            // Issue #582 : une entrée ne change pas de langue. Refusé avant
            // toute autre lecture et tout changement en mémoire (#549), donc
            // avant le détachement ou le rattachement qu'il demanderait.
            if ($contribution->getLocale() !== $locale) {
                throw TranslatableContentLocaleIsImmutableException::refused();
            }

            if (null === $translationGroup) {
                // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
                // le chargement du périmètre — le même qu'à la création sans groupe.
                $this->contentPlacement->detach(
                    $contribution,
                    $this->contributionRepository->findByTranslationGroup($contribution->getTranslationGroup()),
                    $this->contributionRepository->findAll(),
                );
            } else {
                $this->contentPlacement->reattach(
                    $contribution,
                    $translationGroup,
                    $this->contributionRepository->findByTranslationGroup($translationGroup),
                );
            }
            $contribution->update($title, $project, $reference, $url, $summary, $body);
            $this->contributionRepository->save($contribution);

            return $contribution;
        });
    }

    public function delete(Uuid $id): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id): void {
            $contribution = $this->contributionRepository->findOneById($id);

            if (null === $contribution) {
                throw ContributionNotFoundException::forId($id);
            }

            $this->contributionRepository->remove($contribution);
        });
    }

    public function reorder(array $keys): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($keys): void {
            $scope = $this->contributionRepository->findAll();

            $this->orderAssigner->assign($scope, $keys);

            $this->contributionRepository->saveAll($scope);
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
            return $this->contentPlacement->atEndOf($this->contributionRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->contributionRepository->findByTranslationGroup($translationGroup),
        );
    }
}
