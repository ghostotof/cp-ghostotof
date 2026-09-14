<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface CaseStudyAdministratorInterface
{
    /**
     * `$translationGroup` (spec 0004 D1) : groupe d'une entrée existante quand
     * on crée sa version dans une autre langue, `null` pour un contenu neuf —
     * l'entité s'en forge alors un.
     *
     * La position n'est pas un paramètre (spec 0004 D3) : elle se déduit du
     * groupe, ou de la fin du périmètre. Seul l'endpoint d'ordre l'écrira.
     *
     * @throws UnknownTranslationGroupException  groupe inconnu du périmètre
     * @throws TranslationAlreadyExistsException le groupe porte déjà cette langue
     */
    public function create(
        Locale $locale,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        ?Uuid $translationGroup = null,
    ): CaseStudy;

    /**
     * `$translationGroup` porte la sémantique du `PUT` (spec 0004 D3) : `null`
     * détache l'entrée de ses traductions en conservant sa position, un autre
     * groupe l'y rattache et lui en fait hériter la position, le sien ne fait
     * rien.
     *
     * @throws CaseStudyNotFoundException si l'id est inconnu
     * @throws UnknownTranslationGroupException groupe inconnu du périmètre
     * @throws TranslationAlreadyExistsException le groupe porte déjà cette langue
     */
    public function update(
        Uuid $id,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        ?Uuid $translationGroup,
    ): CaseStudy;

    /**
     * @throws CaseStudyNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
