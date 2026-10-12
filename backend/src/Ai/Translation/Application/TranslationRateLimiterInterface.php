<?php

declare(strict_types=1);

namespace App\Ai\Translation\Application;

use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;

/**
 * Quota par compte (et non par IP : l'appelant est authentifié, ROLE_SUPER).
 * Consommé par le processeur après la validation et avant l'appel au
 * fournisseur — une requête invalide ne coûte rien, un appel qui échoue a
 * quand même eu lieu.
 */
interface TranslationRateLimiterInterface
{
    /**
     * @param string $accountId UUID du compte ({@see \App\Shared\Domain\Security\AccountReference}),
     *                          jamais son identifiant de connexion (issue #363)
     *
     * @throws TranslationRateLimitExceededException
     */
    public function consume(string $accountId): void;
}
