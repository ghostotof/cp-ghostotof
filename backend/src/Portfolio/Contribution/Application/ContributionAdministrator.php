<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class ContributionAdministrator implements ContributionAdministratorInterface
{
    public function __construct(
        private ContributionRepositoryInterface $contributionRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
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
        $contribution = new Contribution($locale, $title, $project, $reference, $url, $summary, $body, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->contributionRepository->save($contribution);

        return $contribution;
    }

    public function update(
        Uuid $id,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        ?Uuid $translationGroup,
    ): Contribution {
        $contribution = $this->contributionRepository->findOneById($id);

        if (null === $contribution) {
            throw ContributionNotFoundException::forId($id);
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
    }

    public function delete(Uuid $id): void
    {
        $contribution = $this->contributionRepository->findOneById($id);

        if (null === $contribution) {
            throw ContributionNotFoundException::forId($id);
        }

        $this->contributionRepository->remove($contribution);
    }

    public function reorder(array $keys): void
    {
        $scope = $this->contributionRepository->findAll();

        $this->orderAssigner->assign($scope, $keys);

        $this->contributionRepository->saveAll($scope);
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
