<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;

interface AnonymousCvSectionPresenterInterface
{
    /**
     * @return array{title: string, skills: string, yearsOfExperience: int, achievements: string}
     */
    public function present(AnonymousCvSection $section): array;
}
