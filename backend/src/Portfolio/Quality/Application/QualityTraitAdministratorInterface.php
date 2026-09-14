<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface QualityTraitAdministratorInterface
{
    /**
     * `$translationGroup` (spec 0004 D1) : groupe d'une entrée existante quand
     * on crée sa version dans une autre langue, `null` pour un contenu neuf —
     * l'entité s'en forge alors un. La position reste passée ici ; elle en
     * sortira en B2, quand l'endpoint d'ordre deviendra son seul écrivain.
     */
    public function create(
        Locale $locale,
        string $label,
        int $position,
        ?Uuid $translationGroup = null,
    ): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function update(Uuid $id, string $label, int $position): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
