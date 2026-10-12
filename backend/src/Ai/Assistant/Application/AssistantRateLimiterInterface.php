<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;

/**
 * Quota par compte de l'assistant de parcours (spec 0005 D6). Consommé après
 * la validation de la conversation et avant l'appel au fournisseur : une
 * requête invalide ne coûte rien, un appel qui échoue a quand même eu lieu.
 */
interface AssistantRateLimiterInterface
{
    /**
     * @param string $accountId UUID du compte ({@see \App\Shared\Domain\Security\AccountReference}),
     *                          jamais son identifiant de connexion (issue #363)
     *
     * @throws AssistantRateLimitExceededException
     */
    public function consume(string $accountId): void;
}
