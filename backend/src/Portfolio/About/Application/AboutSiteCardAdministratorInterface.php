<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface AboutSiteCardAdministratorInterface
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
        ?string $iconKey,
        int $position,
        ?Uuid $translationGroup = null,
    ): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function update(Uuid $id, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
