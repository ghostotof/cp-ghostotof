<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * D'où vient la version affichée pour un produit surveillé.
 *
 * Décision D2 de la spécification : la liste des produits est éditoriale, mais
 * les deux versions que le visiteur regarde en premier — PHP et Symfony — sont
 * lues dans le processus qui sert la page. Un radar de veille qui afficherait
 * une version périmée par oubli de saisie se retournerait contre son auteur.
 */
enum VersionSource: string
{
    /** Version saisie au backoffice (PostgreSQL, Node, nginx…). */
    case MANUAL = 'manual';

    /** Version du moteur PHP qui exécute l'application. */
    case RUNTIME_PHP = 'runtime_php';

    /** Version du framework Symfony chargé par l'application. */
    case RUNTIME_SYMFONY = 'runtime_symfony';

    /**
     * Vrai lorsque la version est déterminée à l'exécution, donc jamais stockée.
     */
    public function isResolvedAtRuntime(): bool
    {
        return self::MANUAL !== $this;
    }
}
