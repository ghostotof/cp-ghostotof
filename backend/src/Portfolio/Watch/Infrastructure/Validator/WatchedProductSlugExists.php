<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Vérifie qu'un slug existe bien au catalogue de la source de cycles de vie
 * (décision D10).
 *
 * Confort de saisie et non règle de domaine : un slug fautif ne casse rien —
 * l'entrée s'afficherait simplement « inconnue » sur la page. La contrainte
 * existe pour que l'auteur s'en aperçoive tout de suite plutôt qu'au prochain
 * rafraîchissement.
 *
 * C'est aussi pourquoi elle vit ici et non dans le cas d'usage : la
 * vérification appartient au formulaire d'administration. La placer dans
 * `WatchedProductAdministrator` ferait sortir sur le réseau la commande de
 * seed — et son test avec elle.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class WatchedProductSlugExists extends Constraint
{
    public string $message = 'Le produit « {{ slug }} » est inconnu du catalogue endoflife.date.';
}
