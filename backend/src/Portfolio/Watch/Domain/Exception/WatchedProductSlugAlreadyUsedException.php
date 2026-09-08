<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

/**
 * Exception métier levée quand un produit est déjà suivi sous ce slug.
 *
 * Le slug identifie le produit chez le fournisseur : deux entrées pour le même
 * slug afficheraient deux lignes identiques dans le radar, alimentées par le
 * même appel sortant.
 */
final class WatchedProductSlugAlreadyUsedException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Le produit "%s" est déjà surveillé.', $slug));
    }
}
