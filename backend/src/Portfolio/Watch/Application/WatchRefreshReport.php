<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

/**
 * Compte-rendu d'un rafraîchissement, destiné à l'appelant (commande, plus tard
 * supervision). Ce n'est pas un concept métier : il ne franchit pas la
 * frontière vers le domaine et n'est jamais persisté.
 *
 * Structuré par volet, comme la réponse de l'API : les deux instantanés sont
 * indépendants et peuvent réussir ou échouer séparément. Un rapport plat
 * mélangerait des compteurs sans lien, et il faudrait deviner lequel décrit
 * quoi.
 */
final readonly class WatchRefreshReport
{
    public function __construct(
        public ReleaseCyclesRefreshReport $releaseCycles,
        public VulnerabilityRefreshReport $vulnerabilities,
    ) {
    }

    /**
     * Vrai dès qu'une source n'a pas répondu. La commande en fait un code de
     * sortie non nul : la panne est généralement transitoire, la reprise du
     * travail planifié a du sens.
     */
    public function hasFailure(): bool
    {
        return [] !== $this->releaseCycles->failedSlugs || $this->vulnerabilities->failed;
    }
}
