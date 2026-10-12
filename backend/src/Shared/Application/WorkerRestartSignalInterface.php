<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Demande aux workers Messenger de s'arrêter après leur message en cours
 * (issue #528) ; Kubernetes les relance.
 */
interface WorkerRestartSignalInterface
{
    /**
     * Écrit le signal de redémarrage et vérifie qu'il se relit.
     *
     * @throws WorkerRestartSignalNotSavedException si le signal écrit ne se relit pas : les workers ne
     *                                              s'arrêteraient pas
     */
    public function send(): void;
}
