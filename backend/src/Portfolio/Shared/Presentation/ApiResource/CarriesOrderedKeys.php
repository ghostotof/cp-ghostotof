<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Presentation\ApiResource;

/**
 * Le passage commun aux neuf ressources d'ordre (spec 0004 B4) entre ce que le
 * désérialiseur produit et ce que le domaine attend.
 *
 * Le tableau reçu est du `mixed` : n'importe quelle valeur JSON peut s'y
 * trouver, y compris un tableau imbriqué. `reorder()`, lui, attend une
 * `list<string>`. Les contraintes du DTO ont déjà tranché quand on arrive ici
 * — une valeur non textuelle qui passerait quand même serait un défaut du
 * pipeline de validation, pas une saisie, d'où l'exception logique plutôt
 * qu'un filtrage silencieux qui réordonnerait un périmètre amputé.
 *
 * Même patron que BackofficeTranslationResource::validatedFields().
 *
 * Écart connu et assumé (#170, D3-a) : les neuf DTO posent `Assert\NotBlank`
 * sur la liste, donc un `PUT` vide est un 422, là où `OrderAssigner` accepte
 * un périmètre vide (rien à ordonner, rien à refuser). Sans conséquence : un
 * tableau vide n'affiche pas de barre d'ordre, personne n'envoie cette liste.
 */
trait CarriesOrderedKeys
{
    /**
     * @param array<int|string, mixed> $keys
     *
     * @return list<string>
     */
    private static function validatedKeys(array $keys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new \LogicException('Une clé d\'ordre non textuelle aurait dû être refusée par la validation.');
            }

            $validated[] = $key;
        }

        return $validated;
    }
}
