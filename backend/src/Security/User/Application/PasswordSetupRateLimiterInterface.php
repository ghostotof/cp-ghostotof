<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;

interface PasswordSetupRateLimiterInterface
{
    /**
     * @param string $clientIdentifier identifiant de l'appelant (l'IP côté
     *                                  HTTP) — jamais une donnée qu'il fournit
     *
     * @throws PasswordSetupRateLimitExceededException si le quota est dépassé
     */
    public function consume(string $clientIdentifier): void;
}
