<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface QualityPrincipleAdministratorInterface
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
        string $description,
        string $iconKey,
        int $position,
        ?Uuid $translationGroup = null,
    ): QualityPrinciple;

    /**
     * @throws QualityPrincipleNotFoundException si l'id est inconnu
     */
    public function update(Uuid $id, string $title, string $description, string $iconKey, int $position): QualityPrinciple;

    /**
     * @throws QualityPrincipleNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
