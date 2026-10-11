<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Prouve qu'un message mis en file est consommé par le worker (issue #470).
 */
interface MessengerRoundTripProbeInterface
{
    /**
     * Met un message témoin en file et attend que le worker l'ait traité.
     *
     * @return bool true si le worker l'a traité dans le délai
     */
    public function probe(int $timeoutSeconds): bool;
}
