<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Les cycles de vie publiés pour un produit, tels que le domaine les manipule —
 * jamais le tableau JSON brut du fournisseur.
 */
final readonly class ProductReleaseCycles
{
    /**
     * @param list<ReleaseCycle> $cycles dans l'ordre renvoyé par la source,
     *                                   du plus récent au plus ancien
     */
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $documentationUrl,
        public array $cycles,
    ) {
    }
}
