<?php

declare(strict_types=1);

namespace App\Shared\Application;

use RuntimeException;

/**
 * Rien ne prouve que le signal de redémarrage des workers soit enregistré
 * (issue #528). Panne serveur, sans correspondance HTTP : seule la console la
 * lève, et son message est ce que lit l'opérateur dans les journaux du Job. Il
 * ne cite que la cause, sans nommer le stockage : c'est l'affaire de
 * l'implémentation et de la commande.
 */
final class WorkerRestartSignalNotSavedException extends RuntimeException
{
    /** Le stockage a refusé l'écriture : rien n'a été écrit, aucun worker ne s'arrêtera. */
    public static function refusedByTheStorage(): self
    {
        return new self('Signal d\'arrêt des workers non enregistré : le stockage a refusé l\'écriture (issue #528).');
    }

    /**
     * L'écriture a été acceptée, mais la valeur relue n'est pas celle qui vient
     * d'être écrite : absente, illisible ou plus ancienne. Le signal peut
     * malgré tout être en place (une relecture en échec passager) : rien ne
     * le prouve, et relancer est sans danger.
     */
    public static function notReadBack(): self
    {
        return new self('Signal d\'arrêt des workers non vérifié : la valeur relue n\'est pas celle qui vient d\'être écrite (issue #528).');
    }
}
