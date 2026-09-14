<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D1 — ce qu'un contenu localisé doit savoir dire de son groupe de
 * traduction, pour que les huit contextes partagent une seule règle de
 * placement (`ContentPlacement`) plutôt que huit copies.
 *
 * Volontairement distincte d'`Orderable`, qu'elle étend pour la seule raison
 * qu'un placement écrit une position : `WatchedProduct` est `Orderable` sans
 * être traduisible, et le restera.
 */
interface TranslatableContent extends Orderable
{
    public function getLocale(): Locale;

    public function getTranslationGroup(): Uuid;

    public function getPosition(): int;

    /**
     * Rattache l'entrée au groupe d'un contenu existant : elle en devient la
     * version dans sa propre langue.
     */
    public function attachToTranslationGroup(Uuid $translationGroup): void;

    /**
     * Détache l'entrée de ses traductions. La colonne étant NOT NULL, elle
     * reçoit un groupe neuf plutôt que `null` : une entrée est toujours dans
     * un groupe, seul son cardinal change.
     */
    public function detachFromTranslationGroup(): void;
}
