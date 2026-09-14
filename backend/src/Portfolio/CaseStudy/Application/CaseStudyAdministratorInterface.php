<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface CaseStudyAdministratorInterface
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
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
        ?Uuid $translationGroup = null,
    ): CaseStudy;

    /**
     * @throws CaseStudyNotFoundException si l'id est inconnu
     */
    public function update(
        Uuid $id,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ): CaseStudy;

    /**
     * @throws CaseStudyNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
