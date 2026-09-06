<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class ContributionAdministrator implements ContributionAdministratorInterface
{
    public function __construct(
        private ContributionRepositoryInterface $contributionRepository,
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
        int $position,
    ): Contribution {
        $contribution = new Contribution($locale, $title, $project, $reference, $url, $summary, $body, $position);

        $this->contributionRepository->save($contribution);

        return $contribution;
    }

    public function update(
        int $id,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
    ): Contribution {
        $contribution = $this->contributionRepository->findOneById($id);

        if (null === $contribution) {
            throw ContributionNotFoundException::forId($id);
        }

        $contribution->update($title, $project, $reference, $url, $summary, $body, $position);
        $this->contributionRepository->save($contribution);

        return $contribution;
    }

    public function delete(int $id): void
    {
        $contribution = $this->contributionRepository->findOneById($id);

        if (null === $contribution) {
            throw ContributionNotFoundException::forId($id);
        }

        $this->contributionRepository->remove($contribution);
    }
}
