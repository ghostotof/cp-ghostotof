<?php

declare(strict_types=1);

namespace App\Shared\Application;

use RuntimeException;

/**
 * Le signal de redémarrage des workers n'a pas été enregistré (issue #528) :
 * aucun worker ne s'arrêtera. Panne serveur, sans correspondance HTTP — seule
 * la console l'envoie, et son message est ce que lit l'opérateur dans les
 * journaux du Job ; il ne cite que la cause.
 */
final class WorkerRestartSignalNotSavedException extends RuntimeException
{
    /** `save()` a rendu false : la base a refusé l'écriture (session en lecture seule, table absente…). */
    public static function refusedByTheCache(): self
    {
        return new self('Signal d\'arrêt des workers non enregistré : le cache en base (table cache_items) a refusé l\'écriture (issue #528).');
    }

    /**
     * L'écriture a été acceptée, mais la valeur relue n'est pas celle qui vient
     * d'être écrite : absente, refusée par son MAC (#468) ou plus ancienne.
     */
    public static function notReadBack(): self
    {
        return new self('Signal d\'arrêt des workers non enregistré : la valeur relue dans le cache en base (table cache_items) n\'est pas celle qui vient d\'être écrite (issue #528).');
    }
}
