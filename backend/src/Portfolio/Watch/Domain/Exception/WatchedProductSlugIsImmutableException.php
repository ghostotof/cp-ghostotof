<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

/**
 * Exception métier levée quand une modification tente de changer le slug d'un
 * produit déjà suivi.
 *
 * Suivre un autre produit, c'est créer une autre entrée : le slug construit
 * l'URL interrogée et l'entité ne l'expose pas en écriture. Le refus est
 * explicite plutôt que silencieux — ignorer le champ ferait croire à l'auteur
 * de la modification que son changement a été pris en compte.
 */
final class WatchedProductSlugIsImmutableException extends \DomainException
{
    public static function forSlugs(string $current, string $submitted): self
    {
        return new self(sprintf(
            'Le slug d\'un produit surveillé ne peut pas changer ("%s" → "%s") : supprimez l\'entrée et créez-en une nouvelle.',
            $current,
            $submitted,
        ));
    }
}
