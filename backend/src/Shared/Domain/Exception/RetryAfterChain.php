<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use Throwable;

/**
 * La recherche unique de l'échéance {@see RetryAfterAware} la plus tardive d'une chaîne d'exceptions.
 *
 * Le nom dit l'objet cherché (une échéance) et son terrain (la chaîne des `previous`). Elle vit
 * à côté de l'interface qu'elle cherche : l'écouteur `Retry-After` et le point d'autorisation
 * OAuth (qui rend son 429 sans le lever) la partagent, une seconde boucle finirait par diverger
 * de la première (#651).
 */
final class RetryAfterChain
{
    private function __construct()
    {
    }

    /**
     * Le quota d'échéance la plus tardive de la chaîne, en partant de l'exception elle-même puis en
     * remontant les `previous` : le `Retry-After` n'est jamais raccourci (#651, contrat #743 « jamais
     * trop tôt »). À égalité, le premier trouvé (le plus proche de l'exception levée) l'emporte :
     * la comparaison est stricte.
     */
    public static function latestIn(Throwable $throwable): ?RetryAfterAware
    {
        $latest = null;
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryAfterAware && (null === $latest || $cause->retryAfter > $latest->retryAfter)) {
                $latest = $cause;
            }
        }

        return $latest;
    }
}
