<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;

/**
 * Contrat public en lecture : ni id ni locale (la locale est dans l'URL, l'id
 * n'a de sens qu'au backoffice). Même forme que CaseStudyPresenter.
 */
final class AnonymousCvSectionPresenter implements AnonymousCvSectionPresenterInterface
{
    public function present(AnonymousCvSection $section): array
    {
        return [
            'title' => $section->getTitle(),
            'skills' => $section->getSkills(),
            'yearsOfExperience' => $section->getYearsOfExperience(),
            'achievements' => $section->getAchievements(),
        ];
    }
}
