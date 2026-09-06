<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface ContributionAdministratorInterface
{
    public function create(
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
    ): Contribution;

    /**
     * @throws ContributionNotFoundException si l'id est inconnu
     */
    public function update(
        int $id,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
    ): Contribution;

    /**
     * @throws ContributionNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
