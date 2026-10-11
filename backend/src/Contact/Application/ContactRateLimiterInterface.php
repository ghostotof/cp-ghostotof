<?php

declare(strict_types=1);

namespace App\Contact\Application;

use App\Contact\Domain\Exception\ContactRateLimitExceededException;

interface ContactRateLimiterInterface
{
    /**
     * @param string $clientIdentifier identifiant du visiteur (l'IP côté HTTP,
     *                                  cf. ContactMessageProcessor) — jamais
     *                                  une donnée saisie par l'utilisateur
     *
     * @throws ContactRateLimitExceededException si le quota est dépassé
     */
    public function consume(string $clientIdentifier): void;
}
