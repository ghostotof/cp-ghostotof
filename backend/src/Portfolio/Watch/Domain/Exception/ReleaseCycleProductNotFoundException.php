<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

/**
 * Le produit demandé n'existe pas chez la source de cycles de vie.
 *
 * Distincte de ReleaseCycleSourceUnavailableException à dessein : un slug mal
 * saisi au backoffice est une erreur de contenu, réparable par son auteur, là
 * où une source injoignable est une panne. Les confondre reviendrait à afficher
 * « service indisponible » pour une faute de frappe, et à laisser un produit
 * fantôme passer pour un incident.
 */
final class ReleaseCycleProductNotFoundException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Le produit "%s" est inconnu de la source de cycles de vie.', $slug));
    }
}
