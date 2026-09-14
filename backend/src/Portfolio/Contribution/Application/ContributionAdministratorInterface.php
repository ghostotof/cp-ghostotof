<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface ContributionAdministratorInterface
{
    /**
     * `$translationGroup` (spec 0004 D1) : groupe d'une entrée existante quand
     * on crée sa version dans une autre langue, `null` pour un contenu neuf —
     * l'entité s'en forge alors un. La position reste passée ici ; elle en
     * sortira en B2, quand l'endpoint d'ordre deviendra son seul écrivain.
     */
    public function create(
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
        ?Uuid $translationGroup = null,
    ): Contribution;

    /**
     * @throws ContributionNotFoundException si l'id est inconnu
     */
    public function update(
        Uuid $id,
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
    public function delete(Uuid $id): void;
}
