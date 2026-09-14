<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface AnonymousCvSectionAdministratorInterface
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
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
        ?Uuid $translationGroup = null,
    ): AnonymousCvSection;

    /**
     * @throws AnonymousCvSectionNotFoundException si l'id est inconnu
     */
    public function update(
        Uuid $id,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): AnonymousCvSection;

    /**
     * @throws AnonymousCvSectionNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
